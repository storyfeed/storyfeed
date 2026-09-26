<?php

namespace Storyfeed;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Grouping\NullStrategy;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\SyncToken;
use Storyfeed\Support\VerbFilter;

/**
 * Fluent reader for feeds.
 *
 *   Storyfeed::feed()->limit(30)->get();
 *   Storyfeed::feed()->context($project)->get();
 *   Storyfeed::feed()->actor($user)->limit(15)->get();
 *
 * Three read modes, each naming what you get (docs/grouping.md):
 *
 *   ->live()      today's feed: multi-axis winners ("Sally uploaded 12
 *                 photos", "Bob, Sally and 3 others uploaded…")
 *   ->summary()   the digest: one row per person per day, across verbs
 *                 ("Sally uploaded 12 photos and approved 3 invoices")
 *   ->log()       the atomic timeline, no group nodes
 *
 * The shipped default is live; apps override via `grouping.default`.
 *
 * Re-cut in v0.8 (2026-09-25): `live` took what `summary` used to read, and
 * `summary` became the per-person digest. The old repeats-only `live` is not
 * a mode any more — `grouping.curate => false` reads that way, app-wide.
 *
 * Renamed in v0.7 from flat/grouped/curated. The modes vary GRANULARITY;
 * `curated` is deliberately reserved for a future relevance-ranked view, which
 * varies SELECTION and ORDER — a different axis, and the only one that would
 * earn the word.
 *
 * READ STRATEGY (docs/grouping.md). The page is selected in two phases:
 *
 *  1. Select `limit` FEED ITEMS — not raw rows. Two ordered streams are
 *     merged in PHP: grouped (`feed_groupings` aggregated by hash, carrying
 *     MAX(published_at) and the true COUNT) and solo (activities with no
 *     grouping row, so legacy/imported rows degrade gracefully instead of
 *     vanishing). The cursor walks that merged stream, so "load more"
 *     reaches older groups.
 *  2. Fetch members for the selected groups, capped per group by
 *     `grouping.children_limit` via ROW_NUMBER().
 *
 * The curated, materialized read model (feed_groups) remains slated to
 * replace phase 1 behind the same opaque cursor — gated on benchmarks.
 */
class FeedBuilder
{
    use Conditionable;

    protected ?Model $actor = null;

    protected ?Model $object = null;

    protected ?Model $target = null;

    protected ?Model $context = null;

    protected ?Model $involving = null;

    protected ?string $verb = null;

    /** Verb allowlist/denylist, lazily created by only()/except(). */
    protected ?VerbFilter $verbFilter = null;

    /**
     * Declared unrestricted — the audience decision was "everyone", said out
     * loud. Not a filter: it changes no query, and only doctor reads it.
     */
    protected bool $unrestricted = false;

    /**
     * Roles a Feed class bound as its subject, and may not be rebound.
     *
     * @var array<string, string> role => the class that bound it
     */
    protected array $lockedRoles = [];

    /**
     * Every role bound so far, in order. Read by FeedDefinition to discover
     * what a Feed's scope() actually did — a feed that binds NOTHING is the
     * fail-open case, and the only way to catch it is to look.
     *
     * @var list<string>
     */
    protected array $boundRoles = [];

    /**
     * The registered name this builder was entered through, or null for an
     * ad-hoc read. Not a filter: it changes no query. It is the identity a
     * resolver sees as FeedContext::feed(), so the same snapshot can link
     * differently on the kitchen wall and on the customer's status page.
     */
    protected ?string $feed = null;

    protected int $limit = 30;

    protected ?string $cursor = null;

    /** A named filter was requested but matched no party. */
    protected bool $unresolvable = false;

    /** Read mode: 'log' | 'live' | 'summary'. Null = configured default (live when unconfigured). */
    protected ?string $mode = null;

    /** The summary's calendar period; read only in summary mode. */
    protected Period $period = Period::Day;

    /**
     * Caller constraints on the candidate activities.
     *
     * @var array<int, Closure>
     */
    protected array $callbacks = [];

    /** Ordering rank of each stream, applied at identical timestamps. */
    protected const RANK_GROUP = 0;

    protected const RANK_SOLO = 1;

    /**
     * How many further reads groupedPage() makes when every slice on a page
     * was deleted mid-read. See groupedPage().
     */
    protected const MAX_EMPTY_HOPS = 5;

    public function actor(Model|string $model): static
    {
        $this->assertUnlocked('actor');

        $this->boundRoles[] = 'actor';
        $this->actor = $this->resolve($model);

        return $this;
    }

    public function object(Model|string $model): static
    {
        $this->assertUnlocked('object');

        $this->boundRoles[] = 'object';
        $this->object = $this->resolve($model);

        return $this;
    }

    public function target(Model|string $model): static
    {
        $this->assertUnlocked('target');

        $this->boundRoles[] = 'target';
        $this->target = $this->resolve($model);

        return $this;
    }

    public function context(Model|string $model): static
    {
        $this->assertUnlocked('context');

        $this->boundRoles[] = 'context';
        $this->context = $this->resolve($model);

        return $this;
    }

    /**
     * An entity's own feed: every activity that mentions it, in any role —
     * actor, object, target or context.
     *
     * This is what a project page or a client page wants. `context()` answers
     * the narrower question ("what happened INSIDE this container"), and misses
     * an entity's own creation, since that records it as the object.
     *
     * Indexed via feed_participants — see ActivityBuilder::involving(). An
     * install upgrading into this needs `storyfeed:participants` once.
     */
    public function involving(Model|string $model): static
    {
        $this->assertUnlocked('involving');

        $this->boundRoles[] = 'involving';
        $this->involving = $this->resolve($model);

        return $this;
    }

    /**
     * Renamed: `for()` meant target when recording and involving when
     * reading, which is how it came to be documented as the wrong one.
     *
     * The same reasoning is why a Feed class has no for() either: it would have
     * bound whichever role the class binds, invisibly. A class feed is entered
     * through its constructor (`CustomerFeed::make($order)`), which claims no
     * relationship at all.
     */
    public function for(Model|string $model): never
    {
        throw new InvalidArgumentException(
            'FeedBuilder::for() was renamed to involving() in v0.7 — it filters activities '
            .'involving the model in ANY role, which the old name obscured (on the recording '
            .'side, for() sets the target). Use ->involving($model), or ->target($model) if '
            .'you meant the single role. A Feed CLASS is entered through its constructor '
            .'instead — CustomerFeed::make($model) — and binds its own role; see docs/feeds.md.',
        );
    }

    public function verb(string $verb): static
    {
        $this->verb = $verb;

        return $this;
    }

    /**
     * Restrict this feed to an allowlist of verbs — the seam a customer-facing
     * surface is built on.
     *
     *   Storyfeed::feed()->only(['order.placed', 'order.delivered'])->get();
     *   Storyfeed::feed()->only(['order.*', OrderVerb::Paid])->get();
     *
     * Takes verb strings, FeedVerb cases and plain backed enum cases, mixed;
     * verbs are free-form strings in storage, so this NEVER throws on a verb it
     * does not recognise. A trailing `*` is a prefix wildcard.
     *
     * This is a query filter, not hiding and not authorization: the caller is
     * declaring, visibly, which verbs this feed is ABOUT. Row-level visibility
     * ("this customer's orders only") is still involving()/context()/query().
     * Declare it once per audience with Storyfeed::feeds([...]) rather than at
     * each call site — see docs/feeds.md.
     *
     * Repeat calls NARROW: only(A) then only(B) is A ∩ B, never A ∪ B. That is
     * what makes a preset impossible to widen downstream.
     *
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public function only(array|string|FeedVerb|BackedEnum $verbs): static
    {
        $this->verbFilter()->allow($verbs);

        return $this;
    }

    /**
     * The inverse of only(): every verb EXCEPT these.
     *
     * Weaker than only() by construction — a verb recorded tomorrow is admitted
     * unless someone remembers to add it here — which is why `storyfeed:doctor`
     * reports a verb no feed names at all. Prefer only() for feeds you are
     * defending; except() reads better for feeds that are allowed to grow.
     *
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public function except(array|string|FeedVerb|BackedEnum $verbs): static
    {
        $this->verbFilter()->deny($verbs);

        return $this;
    }

    /**
     * Declare that this feed carries EVERY verb, on purpose.
     *
     *   'portal' => fn (FeedBuilder $feed) => $feed->unrestricted()->summary(),
     *
     * Not a filter and not a widening: it changes no query, and a call site
     * downstream can still narrow with only()/except() exactly as before. What
     * it changes is what `storyfeed:doctor` can say. An OPEN feed — one that
     * simply never called only() or except() — classifies nothing, because
     * forgetting to decide is the hole the `feeds` check exists to catch. But
     * a world feed is a real thing: an operations portal whose feed quietly
     * dropped a verb would be lying about being one, and it was carrying
     * eleven permanent "nobody decided" warnings for a decision that WAS made.
     * The decision was "everything". This is where that gets written down,
     * in code beside the feed rather than in a suppression list.
     *
     * DECLARING IS AN ACT; OMITTING STAYS A WARNING. The two must never
     * collapse into each other, or `'admin' => fn ($feed) => $feed` becomes
     * green forever again. And declaring does not make covered verbs
     * DECIDED: a verb carried only by an unrestricted feed is still reported
     * on every run, as Info rather than Warning, because the check's whole
     * value is firing for the person who did not write this line — the one
     * who records `order.margin_note` six months from now. This declaration
     * would auto-decide every verb that does not exist yet, so it lowers the
     * severity and nothing else. (The same shape as `aggregates.latent`.)
     *
     * Contradicting a verb constraint already declared on the same builder
     * throws: `->only([...])->unrestricted()` is one declaration saying two
     * things, and the read path would honour the filter while doctor honoured
     * the word. The other order is not checked, because narrowing AFTER a
     * declaration is exactly what a call site is allowed to do.
     */
    public function unrestricted(): static
    {
        if ($this->isVerbRestricted()) {
            throw FeedMisconfigured::unrestrictedButFiltered($this->declaredVerbFilter()->patterns());
        }

        $this->unrestricted = true;

        return $this;
    }

    /**
     * Pin the role a Feed class declared as its subject.
     *
     * @internal Called by FeedDefinition::buildFor() and nowhere else.
     *
     * The verb allowlist is unwidenable for free: only(A) then only(B) is
     * A ∩ B, so a call site downstream of a preset can only ever cut further.
     * Scope has no such property, because role filters are single-slot
     * ASSIGNMENTS — a second involving() replaces the first. So
     * `CustomerFeed::make($order)->involving($someoneElse)` would silently swap
     * the scope a surface was built on, and no allowlist protects you from
     * that. Declared scope is therefore pinned; the other four roles stay open,
     * because adding a role NARROWS (they AND together) and narrowing was never
     * the problem.
     *
     * Only a Feed class locks anything. A closure preset, a bare
     * Storyfeed::feed() and $model->storyfeed() are untouched — calling
     * involving() twice on a plain builder still does what it always did.
     */
    public function lockScope(string $role, string $owner): static
    {
        // Nothing is locked implicitly: only a Feed class, and only the roles
        // its scope() actually bound.
        $this->lockedRoles[$role] = $owner;

        return $this;
    }

    /**
     * Name this builder after the feed definition that produced it.
     *
     * @internal Called by FeedDefinition::build() and nowhere else — the
     * identity is DECLARED by the registry, never asserted at a call site,
     * because a call site that can name the feed is a call site that can
     * misname it, and a resolver would then resolve the wrong surface's URL
     * with no symptom. A bare Storyfeed::feed() stays unnamed on purpose.
     */
    public function declareFeed(string $name): static
    {
        $this->feed = $name;

        return $this;
    }

    /**
     * The feed this builder was entered through — `'kitchen'` — or null
     * when it was built ad hoc. The read-back beside declaredMode(), and
     * what NodePresenter hands every resolver on the page.
     */
    public function declaredFeed(): ?string
    {
        return $this->feed;
    }

    /**
     * @internal The roles bound so far, so a Feed's scope() can be checked for
     * having bound anything at all.
     *
     * @return list<string>
     */
    public function boundRoles(): array
    {
        return $this->boundRoles;
    }

    protected function assertUnlocked(string $role): void
    {
        if (isset($this->lockedRoles[$role])) {
            throw FeedMisconfigured::scopeLocked($role, $this->lockedRoles[$role]);
        }
    }

    /**
     * @internal The registry's read-back seam: FeedCoverage runs a preset
     * closure against a fresh builder and asks what it declared. Not contract.
     */
    public function verbFilter(): VerbFilter
    {
        return $this->verbFilter ??= new VerbFilter;
    }

    /**
     * EVERY verb constraint this feed declared, as one filter.
     *
     * @internal The read-back seam tooling reads: doctor's FeedCoverage and
     * Testing\FeedAudience both consume this rather than verbFilter(), so a
     * feed written as `->verb('confirm')` is not invisible to them. A single
     * verb() narrows a feed exactly as `only(['confirm'])` does — same equality
     * in the same SQL — so it folds into the same structure rather than
     * becoming a second thing every consumer has to remember to ask about.
     *
     * What no read-back can see is query(): a closure excluding a verb narrows
     * the feed without saying so in any form. That is stated where it matters —
     * in the assertion messages FeedAudience raises — rather than papered over
     * here.
     */
    public function declaredVerbFilter(): VerbFilter
    {
        $filter = $this->verbFilter ?? new VerbFilter;

        return $this->verb === null ? $filter : $filter->withAllowed($this->verb);
    }

    /** Whether this feed's declaration would show the verb. */
    public function admits(string $verb): bool
    {
        return $this->declaredVerbFilter()->admits($verb);
    }

    /** Whether this feed declared any verb constraint at all. */
    public function isVerbRestricted(): bool
    {
        return ! $this->declaredVerbFilter()->isEmpty();
    }

    /**
     * Whether this feed said, in code, that carrying every verb is the point.
     *
     * @internal The third read-back seam, beside declaredVerbFilter() and
     * declaredMode(). Read by FeedCoverage only — and only for a feed whose
     * filter is empty, since a restricted feed is restricted whatever else it
     * says about itself.
     */
    public function declaredUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * The read mode this feed declared, resolved against the configured
     * default exactly as the read path resolves it.
     *
     * @internal The read-back seam beside declaredVerbFilter(), and it exists
     * for the same reason: mode decides which AXES a surface can ever render
     * (see winning()), so doctor's AggregateCoverage is misleading without it
     * — it asked a `->live()` dashboard for five `object.*` templates that
     * could not have fired. Not contract.
     *
     * Unlike the verb filter this is a presentation default, not a safety
     * property: any call site may override it (docs/feeds.md). Tooling reading
     * it must say "as declared" and must never go silent on the strength of it.
     */
    public function declaredMode(): string
    {
        return $this->mode();
    }

    /**
     * Constrain the candidate activities with anything Eloquent can express.
     *
     *   $project->storyfeed()
     *       ->query(fn (ActivityBuilder $q) => $q->whereNot('verb', 'comment'))
     *       ->summary()->get();
     *
     * The filters above this one are a closed vocabulary — roles, one verb, a
     * mode. This is the way out for everything else: excluding a verb, a date
     * window, several actors, an object type, a `data->` key.
     *
     * The closure receives the candidate query and its return value is IGNORED,
     * which is why this is not called `filter()` — a predicate-shaped closure
     * would silently do nothing. It is not `tap()` either: core's `tap()` hands
     * a callback `$this`, and this hands over a different, inner builder.
     *
     * Runs once per BRANCH of the read, not once per page: measured at once for
     * a log page, and eleven times for a live page carrying one group — the
     * group stream and its window probe, the solo stream, the member fetch, and
     * one distinct count per role; a page that fits in a history window also
     * recounts its groups once. A summary page carrying one row runs it
     * nineteen times (fifteen counts in place of seven), plus once more when
     * the page has rows that might share a crowd. Keep it free of side effects.
     *
     * Constraints reach the whole read, including group children and the
     * distinct-role counts behind ":actors and 3 others", because every branch
     * is built from the same method.
     *
     * Your callbacks always land inside their own parenthesised group, so a
     * top-level `orWhere` widens only what you wrote — never the publish gate,
     * the requested scope, or a verb allowlist. Constraints here can NARROW a
     * feed and can never widen it. See `applyConstraints()`.
     */
    public function query(Closure $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * LOG — the atomic timeline, no group nodes at all. Audit-style views
     * ("my activity") often read better plain.
     */
    public function log(): static
    {
        $this->mode = 'log';

        return $this;
    }

    /**
     * LIVE — today's feed, and the default. Each activity reads under its
     * winning axis: "Sally uploaded 12 photos", "Bob, Sally and 3 others
     * uploaded files to Concur", "Sally commented on 3 projects". The typical
     * home page.
     *
     * Which clusters collapse is WRITE-time policy (`grouping.policy.min_*`,
     * stamped by curation at publish); this mode only reads the stamp. An app
     * that wants repeats only — the pre-0.8 `live` — sets
     * `grouping.curate => false`, and every activity reads through `repeat`.
     *
     * Cursors are mode-specific: never replay one across modes.
     */
    public function live(): static
    {
        $this->mode = 'live';

        return $this;
    }

    /**
     * SUMMARY — the digest: one row per person per day, across verbs.
     *
     *   Storyfeed::feed()->summary();              // today, then yesterday, …
     *   Storyfeed::feed()->summary(Period::Week);  // one row per person per week
     *
     * The period takes the enum or its value, as `onQueue()` takes a queue
     * name, and is the same Period a verb's `groupedPer()` declares. Calendar
     * cuts only: "the last hour" is a filter, not a period.
     *
     * Named `summary`, not `curated`: it collapses MECHANICALLY, and the old
     * name claimed editorial judgement it does not exercise. `curated` is
     * reserved for a relevance-RANKED view, which would be a different axis
     * entirely (selection and order, not granularity).
     *
     * Cursors are mode-specific: never replay one across modes or periods.
     */
    public function summary(Period|string $period = Period::Day): static
    {
        $this->mode = 'summary';
        $this->period = $this->normalizePeriod($period);

        return $this;
    }

    protected function normalizePeriod(Period|string $period): Period
    {
        if ($period instanceof Period) {
            return $period;
        }

        return Period::tryFrom($period) ?? throw new InvalidArgumentException(
            "Unknown summary period [{$period}]. Valid periods: "
            .implode(', ', array_column(Period::cases(), 'value')).'.',
        );
    }

    /**
     * The effective read mode: explicit per-view choice, else the app-wide
     * `grouping.default`. Unknown modes are errors, not features — including
     * the pre-0.7 names, which fail loudly here rather than silently
     * selecting a default.
     */
    protected function mode(): string
    {
        $mode = $this->mode ?? (string) config('storyfeed.grouping.default', 'live');

        if (! in_array($mode, ['log', 'live', 'summary'], true)) {
            // `curated` meant multi-axis winners, which is what live reads now.
            $renamed = ['flat' => 'log', 'curated' => 'live'];

            if (isset($renamed[$mode])) {
                throw new InvalidArgumentException(
                    "Feed mode [{$mode}] was renamed to [{$renamed[$mode]}]. "
                    .'Valid modes: log, live, summary.',
                );
            }

            if ($mode === 'grouped') {
                throw new InvalidArgumentException(
                    'Feed mode [grouped] (repeats only) is not a mode any more. Set '
                    .'`storyfeed.grouping.curate` to false to read repeats only, and use [live]. '
                    .'Valid modes: log, live, summary.',
                );
            }

            throw new InvalidArgumentException(
                "Unknown feed mode [{$mode}]. Valid modes: log, live, summary.",
            );
        }

        return $mode;
    }

    /**
     * Continue from an opaque cursor returned in a previous page.
     */
    public function cursor(?string $cursor): static
    {
        $this->cursor = $cursor;

        return $this;
    }

    /**
     * Paginate the feed using the current request's opaque cursor.
     */
    public function cursorPaginate(?int $perPage = null, string $cursorName = 'cursor'): FeedPaginator
    {
        $perPage ??= $this->limit;

        if ($perPage < 1) {
            throw new InvalidArgumentException('The number of feed items per page must be at least 1.');
        }

        $cursor = FeedPaginator::resolveCurrentCursor($cursorName);
        $page = (clone $this)->limit($perPage)->cursor($cursor?->encode())->get();

        return new FeedPaginator($page, $perPage, $cursor, $cursorName);
    }

    /**
     * One page of the feed. An empty `items` means the end of the feed: a
     * read whose activities were all deleted mid-read follows its own cursor
     * and reads again, up to five times. Only a pruning burst that empties
     * every one of those reads returns an empty page with a live
     * `next_cursor`. `next_cursor: null` is always the end.
     */
    public function get(): FeedPage
    {
        // Captured once: the published() gate must not shift between the
        // group-selection query and the member fetch.
        $now = Carbon::now();

        return $this->shouldGroup()
            ? $this->groupedPage($now)
            : $this->logPage($now);
    }

    /**
     * A string names a Party. On the read path this LOOKS UP only — a query
     * must never create rows — so an unknown name matches nothing.
     */
    protected function resolve(Model|string $model): ?Model
    {
        if (! is_string($model)) {
            return $model;
        }

        $party = config('storyfeed.models.party', Party::class);

        $resolved = $party::find($model);

        // Filtering by a name nobody has used must match nothing — not
        // silently drop the filter and return the whole feed.
        $this->unresolvable = $this->unresolvable || $resolved === null;

        return $resolved;
    }

    protected function shouldGroup(): bool
    {
        return $this->mode() !== 'log'
            && ! is_a(config('storyfeed.grouping.strategy'), NullStrategy::class, true);
    }

    /**
     * NO EMPTY PAGE MID-FEED (2026-09-22). A read that drops every slice (see
     * groupedSlices()) follows its own cursor and reads again, so a reader
     * never has to loop: an empty `items` means the end of the feed. The one
     * exception is the bound — MAX_EMPTY_HOPS further reads, all emptied —
     * which only a pathological pruning burst can reach; the page then comes
     * back empty with the last cursor reached, still well-formed and still
     * resumable.
     *
     * `log()` cannot drop: it selects and hydrates in one query.
     */
    protected function groupedPage(Carbon $now): FeedPage
    {
        $cursor = $this->cursor;

        try {
            for ($hop = 0; ; $hop++) {
                [$slices, $next] = $this->groupedSlices($now);

                if ($slices->isNotEmpty() || $next === null || $hop === self::MAX_EMPTY_HOPS) {
                    return new FeedPage($slices, $next, $this->presenter(), SyncToken::current());
                }

                $this->cursor = $next;
            }
        } finally {
            // The builder is reusable; hopping must not move the caller's cursor.
            $this->cursor = $cursor;
        }
    }

    /**
     * One read: phase 1 selects the page, phase 2 hydrates it.
     *
     * @return array{Collection<int, GroupSlice>, string|null}
     */
    protected function groupedSlices(Carbon $now): array
    {
        $candidates = $this->selectItems($now);

        $more = $candidates->count() > $this->limit;
        $candidates = $candidates->take($this->limit)->values();

        $next = $more ? $this->encodeCursor($candidates->last()) : null;

        $groups = $candidates->filter(fn (FeedCandidate $candidate) => $candidate->isGroup())->values();

        $slices = $this->mode() === 'summary'
            ? $this->summarySlices($now, $candidates, $groups)
            : $this->groupSlices($now, $candidates, $groups);

        return [$slices, $next];
    }

    /**
     * Phase 2 for live: one slice per selected group, members and distinct
     * counts fetched for the page's groups only.
     *
     * @param  Collection<int, FeedCandidate>  $candidates
     * @param  Collection<int, FeedCandidate>  $groups
     * @return Collection<int, GroupSlice>
     */
    protected function groupSlices(Carbon $now, Collection $candidates, Collection $groups): Collection
    {
        $members = $this->fetchMembers($now, $groups);
        ['distinct' => $distinct, 'tombstoned' => $tombstoned] = $this->countDistinctRoles($now, $groups);

        $slices = $candidates->map(function (FeedCandidate $candidate) use ($members, $distinct, $tombstoned): GroupSlice {
            if ($candidate->activity !== null) {
                return GroupSlice::solo($candidate->activity);
            }

            $key = $this->groupKey($candidate);

            return GroupSlice::group(
                (string) $candidate->axis,
                (string) $candidate->hash,
                $candidate->count,
                $members->get($key) ?? $this->activityModel()->newCollection(),
                $distinct[$key] ?? [],
                $tombstoned[$key] ?? [],
            );
        })
            // PHASE 2 IS AUTHORITATIVE (2026-08-12, found in the Newsroom's
            // production logs). The two phases run as separate queries, so a
            // candidate selected in phase 1 can have its activities deleted
            // before phase 2 hydrates them — a real race, not a bad state:
            // both phases share one soft-delete scope, so deleting up front
            // stays consistent and cannot reproduce it. The Newsroom hit it
            // during the shape-column drift, when an every-5-minute trickle
            // was taking the ORPHAN delete path over a 200-row budget while
            // open tabs polled every 10 seconds.
            //
            // An empty slice took down the WHOLE render, two ways: count == 1
            // (no HAVING floor in groupStream, so it happens) fails
            // isGroup() and passes null into activityNode(); count > 1
            // reaches groupNode() and dies on the head member. Guarding one
            // presenter line would have left the other crash live, so the
            // drop belongs here, at the boundary — and activityNode() keeps
            // its non-nullable Activity instead of pushing the empty case
            // into every caller.
            //
            // Dropping is the honest answer, not hiding: the activities are
            // genuinely gone, so omitting their node degrades gracefully the
            // way a missing snapshot does. `$next` is already computed above
            // from the unfiltered candidates, so pagination neither skips a
            // page nor stalls. A read that drops every slice is not returned
            // empty: groupedPage() follows `$next` and reads again.
            ->reject(fn (GroupSlice $slice) => $slice->members->isEmpty())
            ->values();

        return $slices;
    }

    /**
     * Phase 2 for summary: the page's person-periods, with people whose
     * whole period was one identical thing merged into a crowd, each row
     * carrying its per-verb phrases.
     *
     * THE CROWD MERGE IS PAGE-LOCAL, and that is policy, not contract. Two
     * people who each only checked in at the fair read as one row when both
     * rows land on the same page; a crowd that straddles a page boundary
     * reads as two rows, one per page. Nothing is hidden either way, and the
     * cursor is untouched: it was minted from the unmerged candidates, so
     * the next page starts exactly where it would have. Merging across pages
     * would need the whole period in hand, which is the materialized read
     * model's job (docs/grouping.md), not this read's.
     *
     * @param  Collection<int, FeedCandidate>  $candidates
     * @param  Collection<int, FeedCandidate>  $groups
     * @return Collection<int, GroupSlice>
     */
    protected function summarySlices(Carbon $now, Collection $candidates, Collection $groups): Collection
    {
        $units = $this->crowds($now, $groups);
        $members = $this->fetchMembers($now, $groups, byVerb: true);
        $aggregates = $this->summaryAggregates($now, $groups, $units);

        $counts = [];
        $keys = [];

        foreach ($groups as $group) {
            $key = $this->groupKey($group);
            $unit = $units[$key];
            $counts[$unit] = ($counts[$unit] ?? 0) + $group->count;
            $keys[$unit][] = $key;
        }

        $emitted = [];
        $slices = [];

        foreach ($candidates as $candidate) {
            if ($candidate->activity !== null) {
                $slices[] = GroupSlice::solo($candidate->activity);

                continue;
            }

            $unit = $units[$this->groupKey($candidate)];

            // A crowd sits where its newest person would have.
            if (isset($emitted[$unit])) {
                continue;
            }

            $emitted[$unit] = true;

            /** @var EloquentCollection<int, Activity> $all */
            $all = $this->activityModel()->newCollection(
                Collection::make($keys[$unit])
                    ->flatMap(fn (string $key) => $members->get($key)?->all() ?? [])
                    ->sort(fn (Activity $a, Activity $b): int => $this->normalizeTimestamp($b->published_at) <=> $this->normalizeTimestamp($a->published_at)
                        ?: $b->getKey() <=> $a->getKey())
                    ->values()
                    ->all(),
            );

            if ($all->isEmpty()) {
                continue; // deleted between the phases; see groupSlices()
            }

            $phrases = Collection::make($aggregates['phrases'][$unit] ?? [])
                ->map(fn (array $phrase, int|string $verb) => [
                    'verb' => (string) $verb,
                    'count' => $phrase['count'],
                    'first' => $phrase['first'],
                    'distinct' => $phrase['distinct'],
                    'members' => $all->filter(fn (Activity $a) => $a->verb === (string) $verb)->values(),
                ])
                // In the order the period happened: each verb where it first
                // occurred ("checked in, got a balloon and went on 3 rides"),
                // read from every member, not the capped ones. Then the verb,
                // so the order never depends on the database's.
                ->sort(fn (array $a, array $b): int => strcmp($a['first'], $b['first'])
                    ?: strcmp($a['verb'], $b['verb']))
                ->map(fn (array $phrase) => array_diff_key($phrase, ['first' => true]))
                ->values()
                ->all();

            $slices[] = GroupSlice::summary(
                (string) $candidate->axis,
                count($keys[$unit]) === 1 ? (string) $candidate->hash : 'crowd'."\x1f".implode("\x1e", array_map(
                    fn (string $key) => explode("\x1f", $key, 2)[1],
                    $keys[$unit],
                )),
                $this->period,
                $counts[$unit],
                $all->take($this->childrenLimit())->values(),
                $aggregates['distinct'][$unit] ?? [],
                $aggregates['tombstoned'][$unit] ?? [],
                $phrases,
            );
        }

        return Collection::make($slices);
    }

    /**
     * Which selected person-periods read as one crowd row: those whose
     * whole period was ONE activity, and the same verb at the same target
     * (or at none) as the others'. "Murray Bauman and Karen Wheeler checked
     * in at the Fun Fair."
     *
     * One activity each, not one verb each: a person who checked in twice
     * keeps their own row ("checked in at the Fun Fair 2 times"), because a
     * crowd row counts people and would make that sentence untrue. An
     * actorless activity has no partition row to begin with and reads solo:
     * anonymous is not a crowd.
     *
     * @param  Collection<int, FeedCandidate>  $groups  in page order
     * @return array<string, string> group key => the unit it reads in (its first group's key)
     */
    protected function crowds(Carbon $now, Collection $groups): array
    {
        $units = [];

        foreach ($groups as $group) {
            $units[$this->groupKey($group)] = $this->groupKey($group);
        }

        if ($groups->count() < 2) {
            return $units;
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $shapes = $this->activityModel()->getConnection()->query()
            ->fromSub($this->selectedGroupMembers($now, $groups)->toBase()->select([
                "{$groupings}.bucket as group_bucket",
                "{$groupings}.hash as group_hash",
                "{$activities}.verb",
                "{$activities}.target_type",
                "{$activities}.target_id",
            ]), 'm')
            ->groupBy('group_bucket', 'group_hash')
            ->select(['group_bucket', 'group_hash'])
            ->selectRaw('count(*) as members')
            ->selectRaw('min(verb) as verb')
            ->selectRaw('count(target_type) as targeted')
            ->selectRaw('min(target_type) as min_type, max(target_type) as max_type')
            ->selectRaw('min(target_id) as min_id, max(target_id) as max_id')
            ->get();

        $crowds = [];

        foreach ($shapes as $shape) {
            $untargeted = (int) $shape->targeted === 0;
            $oneTarget = (int) $shape->targeted === (int) $shape->members
                && $shape->min_type === $shape->max_type
                && (string) $shape->min_id === (string) $shape->max_id;

            // One activity, not one kind of activity: a crowd row's count is
            // its number of people, so ":count times" on it stays honest.
            if ((int) $shape->members !== 1 || ! ($untargeted || $oneTarget)) {
                continue;
            }

            // Same period VALUE, not just the same bucket: the day is the
            // key's last segment (`user:7:2026-09-25`). A key long enough to
            // be digested has lost it, and keeps its own row.
            $hash = (string) $shape->group_hash;

            if (! str_contains($hash, ':')) {
                continue;
            }

            $thing = implode("\x1f", [$shape->group_bucket, substr($hash, strrpos($hash, ':') + 1), $shape->verb, $untargeted ? '' : $shape->min_type, $untargeted ? '' : (string) $shape->min_id]);

            $crowds[$thing][] = $shape->group_bucket."\x1f".$shape->group_hash;
        }

        foreach ($crowds as $keys) {
            if (count($keys) < 2) {
                continue;
            }

            // The unit is the crowd's first person in page order.
            $first = collect(array_keys($units))->first(fn (string $key) => in_array($key, $keys, true));

            foreach ($keys as $key) {
                $units[$key] = (string) $first;
            }
        }

        return $units;
    }

    /**
     * The digest's true numbers, per row and per phrase: member counts per
     * verb, and distinct counts per role, both across every member rather
     * than the capped children. A crowd's rows count together, so two
     * people who checked in at one fair are one target, not two.
     *
     * Fifteen queries a page: one for the phrase counts, and two per role,
     * because a role's distinct count per row cannot be summed from its
     * counts per phrase (one target, three verbs). The Step 3 read model
     * absorbs them someday; settled periods never change.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @param  array<string, string>  $units  group key => unit
     * @return array{distinct: array<string, array<string, int>>, tombstoned: array<string, array<string, int>>, phrases: array<string, array<string, array{count: int, first: string, distinct: array<string, int>}>>}
     */
    protected function summaryAggregates(Carbon $now, Collection $groups, array $units): array
    {
        $result = ['distinct' => [], 'tombstoned' => [], 'phrases' => []];

        if ($groups->isEmpty()) {
            return $result;
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();
        $connection = $this->activityModel()->getConnection();
        [$unit, $bindings] = $this->unitColumn($groups, $units);

        $members = fn (array $columns) => $this->selectedGroupMembers($now, $groups)
            ->toBase()
            ->selectRaw("{$unit} as unit_key", $bindings)
            ->addSelect($columns);

        $counts = $connection->query()
            ->fromSub($members(["{$activities}.verb", "{$activities}.published_at"]), 'm')
            ->groupBy('unit_key', 'verb')
            ->select(['unit_key', 'verb'])
            ->selectRaw('count(*) as total')
            ->selectRaw('min(published_at) as first_at')
            ->get();

        foreach ($counts as $row) {
            $result['phrases'][$row->unit_key][$row->verb] = [
                'count' => (int) $row->total,
                'first' => $this->normalizeTimestamp($row->first_at),
                'distinct' => [],
            ];
        }

        foreach (ActivityRoles::GROUPABLE as $role) {
            $identity = ["{$activities}.{$role}_type", "{$activities}.{$role}_id"];

            $perPhrase = $connection->query()
                ->fromSub($members(["{$activities}.verb", ...$identity])->whereNotNull("{$activities}.{$role}_type")->distinct(), 'd')
                ->groupBy('unit_key', 'verb')
                ->select(['unit_key', 'verb'])
                ->selectRaw('count(*) as total')
                ->get();

            foreach ($perPhrase as $row) {
                if (isset($result['phrases'][$row->unit_key][$row->verb])) {
                    $result['phrases'][$row->unit_key][$row->verb]['distinct'][$role] = (int) $row->total;
                }
            }

            $perRow = $connection->query()
                ->fromSub($members($identity)->whereNotNull("{$activities}.{$role}_type")->distinct(), 'd')
                ->groupBy('unit_key')
                ->select(['unit_key'])
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when {$role}_type = ? then 1 else 0 end) as tombstoned", [FeedTombstone::MORPH_ALIAS])
                ->get();

            foreach ($perRow as $row) {
                $result['distinct'][$row->unit_key][$role] = (int) $row->total;
                $result['tombstoned'][$row->unit_key][$role] = (int) $row->tombstoned;
            }
        }

        return $result;
    }

    /**
     * The row each member counts in, as SQL: its group's own key, or its
     * crowd's. A CASE over the page's groups, so a crowd aggregates in the
     * same query as everything else on the page.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @param  array<string, string>  $units
     * @return array{0: string, 1: list<string>}
     */
    protected function unitColumn(Collection $groups, array $units): array
    {
        $groupings = $this->groupingModel()->getTable();
        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $bucket = $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket');
        $hash = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');

        $sql = 'case';
        $bindings = [];

        foreach ($groups as $group) {
            $sql .= " when {$bucket} = ? and {$hash} = ? then ?";
            array_push($bindings, (string) $group->axis, (string) $group->hash, $units[$this->groupKey($group)]);
        }

        return [$sql.' end', $bindings];
    }

    /**
     * A presenter that knows which feed it is presenting — a copy, so a
     * container singleton could never carry one page's name into the next.
     */
    protected function presenter(): NodePresenter
    {
        return app(NodePresenter::class)->forFeed($this->feed);
    }

    protected function logPage(Carbon $now): FeedPage
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $cursor = $this->logCursorState($activities);

        // The same keyset predicate `cursorPaginate()` would build, applied by
        // hand: the paginator stringifies a Carbon parameter through
        // `__toString()`, which is whole seconds, so a cursor minted from a row
        // at `.400000` would point at `.000000` and the next page would skip or
        // repeat every row in between. The parameter names are the ones the
        // paginator used, so a cursor minted before this method existed still
        // decodes and still lands where it was minted.
        $rows = $this->filteredActivities($now)
            // Flat is the atomic timeline: composite MEMBERS appear, the
            // object-less parent STORY does not (its self-row marks it).
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")
                ->where("{$groupings}.bucket", 'composite')
                ->whereColumn("{$groupings}.hash", "{$activities}.uid"))
            ->when($cursor !== null, fn (ActivityBuilder $q) => $q->where(fn (ActivityBuilder $after) => $after
                ->where("{$activities}.published_at", '<', $cursor['published_at'])
                ->orWhere(fn (ActivityBuilder $tie) => $tie
                    ->where("{$activities}.published_at", '=', $cursor['published_at'])
                    ->where("{$activities}.id", '<', $cursor['id']))))
            ->with(ActivityRoles::cachedRelations())
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->limit($this->limit + 1)
            ->get();

        $more = $rows->count() > $this->limit;
        $page = $rows->take($this->limit);

        $slices = $page->map(fn (Activity $activity) => GroupSlice::solo($activity));

        /** @var Activity|null $last */
        $last = $page->last();

        $next = $more && $last !== null
            ? (new Cursor([
                "{$activities}.published_at" => $this->normalizeTimestamp($last->published_at),
                "{$activities}.id" => $last->getKey(),
            ]))->encode()
            : null;

        return new FeedPage(Collection::make($slices->all()), $next, $this->presenter(), SyncToken::current());
    }

    /**
     * The flat timeline's cursor: the keyset `cursorPaginate()` minted, read
     * back under the same names. Not contract — see cursorState().
     *
     * @return array{published_at: string, id: int|string}|null
     */
    protected function logCursorState(string $activities): ?array
    {
        $cursor = $this->decodedCursor();

        if ($cursor === null) {
            return null;
        }

        $parameters = $cursor->toArray();

        if (! isset($parameters["{$activities}.published_at"], $parameters["{$activities}.id"])) {
            return null;
        }

        return [
            'published_at' => $this->normalizeTimestamp($parameters["{$activities}.published_at"]),
            'id' => $parameters["{$activities}.id"],
        ];
    }

    /**
     * Phase 1 — select one page of FEED ITEMS by merging the grouped and solo
     * streams. Ordering is total by construction: (latest DESC, stream rank
     * ASC, then a PER-STREAM tiebreak — groups by (axis, hash) ASC, solos by
     * id DESC), which is what makes the cursor deterministic when several
     * items share a MAX(published_at) — routine on bulk imports.
     *
     * THE TWO TIEBREAKS POINT DIFFERENT WAYS ON PURPOSE, and rank is what
     * makes that safe: every group sorts before every solo at a shared
     * timestamp, so a group is never tiebroken against a solo and each stream
     * only has to agree with its own SQL and its own cursor predicate.
     *
     * Solos descend (2026-08-26) because THAT tiebreak means something: id
     * DESC is newest-first, the same order `logPage()` has always used. While
     * it ascended, two activities published in the same second came back one
     * way round in `log()` and the other way round in `live()` — nothing
     * nondeterministic, an exact REVERSAL on a mode switch, which is why a
     * consumer's rename test flipped rather than flickered when they turned
     * grouping on. On an audit surface "which happened first" is the question,
     * and rows sharing a timestamp are routine on seeds and imports.
     *
     * Groups keep ascending because (axis, hash) is arbitrary-but-stable
     * naming, not recency: reversing it would reorder every tied page — the
     * `actors` group and the `repeat` group swap places — while making no
     * page more correct. A tiebreak that carries no meaning should not be
     * churned for symmetry with one that does.
     *
     * Since timestamps carry microseconds (W123, 2026-09-10) a tie is a
     * genuinely simultaneous pair — or a bulk import that stamped one instant
     * across a batch — rather than anything published in the same second.
     * The tiebreak still has to be total and still has to agree with its own
     * cursor predicate; it just decides far less often.
     *
     * @return Collection<int, FeedCandidate>
     */
    protected function selectItems(Carbon $now): Collection
    {
        $cursor = $this->cursorState();

        return $this->groupStream($now, $cursor)
            ->concat($this->soloStream($now, $cursor))
            ->sort(fn (FeedCandidate $a, FeedCandidate $b): int => strcmp($b->latest, $a->latest)
                ?: ($this->rank($a) <=> $this->rank($b))
                ?: $this->compareTiebreak($a, $b))
            ->values();
    }

    protected function rank(FeedCandidate $candidate): int
    {
        return $candidate->isGroup() ? self::RANK_GROUP : self::RANK_SOLO;
    }

    /**
     * Only ever called for candidates of the same stream — the rank
     * comparison has already separated the two.
     *
     * Groups compare on (axis, hash), matching the SQL tuple comparison in
     * the cursor predicate exactly. Comparing on hash alone would assume no
     * two axes can ever produce the same hash string — true in practice,
     * but nothing enforces it.
     */
    protected function compareTiebreak(FeedCandidate $a, FeedCandidate $b): int
    {
        if ($a->hash !== null && $b->hash !== null) {
            return strcmp((string) $a->axis, (string) $b->axis) ?: strcmp($a->hash, $b->hash);
        }

        if ($a->activity !== null && $b->activity !== null) {
            return $b->activity->getKey() <=> $a->activity->getKey();
        }

        return 0;
    }

    /**
     * @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function groupStream(Carbon $now, ?array $cursor): Collection
    {
        // THE AGGREGATE IS PAID PER ELIGIBLE ACTIVITY, NOT PER PAGE. Grouping
        // by hash means joining every eligible activity to its winning
        // grouping row before a single group can be ranked; on MySQL 8.4 at
        // 50k activities that join was ~250ms of a ~300ms query, and no index
        // moves it (W103, 2026-09-09). A cursor does not help either: the
        // HAVING predicate filters groups AFTER the aggregate, so page 100
        // costs what page 1 costs.
        //
        // So the aggregate runs over a WINDOW of recent history first, and
        // only widens when the window comes up short. The window is exact,
        // not approximate, by this argument: a group's MAX(published_at) is
        // the published_at of its newest member, so every group whose newest
        // member lies inside [floor, ceiling] appears in the windowed
        // aggregate with its TRUE latest; every group that does not appear
        // has latest < floor, which sorts after every group that does. If
        // the window yields a full page (limit + 1 rows, so `more` is known),
        // nothing below the floor could have ranked on it. If it does not,
        // widen and try again; the last depth is unbounded and is the
        // aggregate exactly as it always was.
        //
        // Two things the window changes that have to be put back:
        //
        //  - Members ABOVE the ceiling. The ceiling is the cursor's timestamp,
        //    and a group already shown on an earlier page can have older
        //    members inside this window, where the windowed MAX would fall
        //    below the cursor and pass the HAVING. Those groups are excluded
        //    by an antijoin on "any eligible member newer than the ceiling",
        //    which is exactly the condition under which the true latest
        //    fails the cursor predicate. Without a cursor there is no ceiling
        //    and nothing to exclude.
        //  - COUNT(*) inside the window undercounts a group whose older
        //    members lie below the floor, so a windowed page recounts its
        //    selected groups in one bounded query — the same shape
        //    countDistinctRoles() already runs seven times a page.
        //
        // Measured on MySQL 8.4.11, the newsroom's 50k fixture, page size 30:
        // see docs/journal for the W114 numbers.
        $ceiling = $cursor['latest'] ?? null;
        $rows = Collection::make();
        $windowed = false;

        foreach ($this->windowDepths() as $depth) {
            $floor = $depth === null ? null : $this->windowFloor($now, $ceiling, $depth);

            // A depth deeper than eligible history IS the unbounded read.
            $windowed = $floor !== null;

            $rows = $this->groupAggregate($now, $cursor, $windowed ? $floor : null, $windowed ? $ceiling : null);

            if (! $windowed || $rows->count() > $this->limit) {
                break;
            }
        }

        $groups = $rows->map(fn (object $row) => FeedCandidate::group(
            $this->normalizeTimestamp($row->latest),
            (string) $row->bucket,
            (string) $row->hash,
            (int) $row->members,
        ));

        return $windowed ? $this->recountMembers($now, $groups) : $groups;
    }

    /**
     * How many eligible activities, newest first, each windowed attempt of
     * the group aggregate covers; null is the unbounded aggregate and must
     * come last. Geometric so the retries together cost little more than the
     * read that finally succeeds, and so a feed that is all solos (no group
     * can ever fill a page) falls through to the unbounded read in two
     * cheap attempts rather than many.
     *
     * @return non-empty-list<int|null>
     */
    protected function windowDepths(): array
    {
        return [$this->limit * 16, $this->limit * 256, null];
    }

    /**
     * The published_at of the `$depth`-th eligible activity at or below the
     * ceiling, newest first — the window's inclusive floor — or null when
     * eligible history is shallower than that, in which case no window is
     * needed.
     */
    protected function windowFloor(Carbon $now, ?string $ceiling, int $depth): ?string
    {
        $activities = $this->activityModel()->getTable();

        $value = $this->filteredActivities($now)
            ->when($ceiling !== null, fn (ActivityBuilder $q) => $q->where("{$activities}.published_at", '<=', $ceiling))
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->offset($depth - 1)
            ->limit(1)
            ->toBase()
            ->value("{$activities}.published_at");

        return $value === null ? null : $this->normalizeTimestamp($value);
    }

    /**
     * The group aggregate itself: winning grouping rows of eligible
     * activities, grouped by (bucket, hash), newest group first, with the
     * cursor applied as a HAVING over the aggregate. With both bounds null
     * this is the whole of history and needs no correction; with a window it
     * is the windowed read groupStream() describes, ceiling-excluded.
     *
     * @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, \stdClass> rows of bucket, hash, latest, members
     */
    protected function groupAggregate(Carbon $now, ?array $cursor, ?string $floor, ?string $ceiling): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $bucketColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket');
        $hashColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');
        $latest = 'max(fa.fa_published)';

        $filtered = $this->filteredActivities($now)
            ->when($floor !== null, fn (ActivityBuilder $q) => $q->where("{$activities}.published_at", '>=', $floor))
            ->when($ceiling !== null, fn (ActivityBuilder $q) => $q->where("{$activities}.published_at", '<=', $ceiling))
            ->select(["{$activities}.id as fa_id", "{$activities}.published_at as fa_published"]);

        $query = $this->groupingModel()->newQuery()
            ->where($this->winning())
            ->joinSub($filtered, 'fa', fn (JoinClause $join) => $join->on('fa.fa_id', '=', "{$groupings}.activity_id"))
            ->groupBy("{$groupings}.bucket", "{$groupings}.hash")
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->selectRaw("{$latest} as latest")
            ->selectRaw('count(*) as members')
            ->toBase();

        // Groups rank before solos at an identical timestamp, so a solo
        // cursor has already consumed every group in that tie.
        if ($cursor !== null && $cursor['rank'] === self::RANK_GROUP) {
            $query->havingRaw(
                "({$latest} < ? or ({$latest} = ? and ({$bucketColumn} > ? or ({$bucketColumn} = ? and {$hashColumn} > ?))))",
                [$cursor['latest'], $cursor['latest'], $cursor['axis'], $cursor['axis'], $cursor['hash']],
            );
        } elseif ($cursor !== null) {
            $query->havingRaw("{$latest} < ?", [$cursor['latest']]);
        }

        if ($ceiling === null) {
            return $query
                ->orderByRaw("{$latest} desc")
                ->orderBy("{$groupings}.bucket")
                ->orderBy("{$groupings}.hash")
                ->limit($this->limit + 1)
                ->get();
        }

        // Windowed under a cursor: drop every group with an eligible member
        // newer than the ceiling — its true latest is above the cursor, and
        // it has already been paged. Wrapping (rather than a HAVING
        // subquery) keeps the correlated reference on plain derived-table
        // columns, which every supported grammar accepts.
        $newer = $this->winningMembers($now)
            ->whereColumn("{$groupings}.bucket", 'windowed.bucket')
            ->whereColumn("{$groupings}.hash", 'windowed.hash')
            ->where("{$activities}.published_at", '>', $ceiling)
            ->selectRaw('1')
            ->toBase();

        return $this->groupingModel()->getConnection()->query()
            ->fromSub($query, 'windowed')
            ->whereNotExists($newer)
            ->orderBy('latest', 'desc')
            ->orderBy('bucket')
            ->orderBy('hash')
            ->limit($this->limit + 1)
            ->get();
    }

    /**
     * The true member count of each selected group, replacing the windowed
     * COUNT(*) — bounded to the page's groups, like every other phase-2 read.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return Collection<int, FeedCandidate>
     */
    protected function recountMembers(Carbon $now, Collection $groups): Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        $groupings = $this->groupingModel()->getTable();

        $counts = $this->selectedGroupMembers($now, $groups)
            ->toBase()
            ->groupBy("{$groupings}.bucket", "{$groupings}.hash")
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->selectRaw('count(*) as members')
            ->get()
            ->keyBy(fn (object $row) => $row->bucket."\x1f".$row->hash);

        return $groups->map(fn (FeedCandidate $group) => FeedCandidate::group(
            $group->latest,
            (string) $group->axis,
            (string) $group->hash,
            (int) ($counts->get($this->groupKey($group))->members ?? $group->count),
        ));
    }

    /**
     * The grouping predicate, applied wherever the groupings table is in
     * play.
     *
     * live: `winner = true` is the curated answer, and a row with NO winner
     * stamped anywhere for its activity falls back to `repeat` — so adopters
     * upgrade into the winner column with no backfill cliff
     * (`storyfeed:curate` settles history incrementally), and an app with
     * curation stamping disabled reads as repeat-only.
     *
     * summary: the period's partition bucket, one equality. Every activity
     * with an actor has exactly one such row, and nothing is ever stamped
     * on it, so curation and its races never reach the digest.
     */
    protected function winning(): Closure
    {
        $groupings = $this->groupingModel()->getTable();

        if ($this->mode() === 'summary') {
            $bucket = $this->summaryBucket();

            return fn ($query) => $query->where("{$groupings}.bucket", $bucket);
        }

        return function ($query) use ($groupings) {
            $query->where("{$groupings}.winner", true)
                ->orWhere(fn ($fallback) => $fallback
                    ->where("{$groupings}.bucket", 'repeat')
                    ->whereNotExists(fn (QueryBuilder $sub) => $sub
                        ->selectRaw('1')
                        ->from("{$groupings} as w")
                        ->whereColumn('w.activity_id', "{$groupings}.activity_id")
                        ->where('w.winner', true)));
        };
    }

    /**
     * The partition bucket summary() reads. An app whose registry dropped it
     * (`Storyfeed::axes([...], merge: false)`) gets an error naming it rather
     * than a digest in which everything reads solo.
     */
    protected function summaryBucket(): string
    {
        $axis = app(StoryfeedManager::class)->summaryAxis($this->period);

        if ($axis === null) {
            throw new FeedMisconfigured(
                "summary(Period::{$this->period->name}) reads the [summary.{$this->period->value}] axis, which is not registered. "
                .'Keep the built-in summary axes when replacing the registry, or read this feed with live().',
            );
        }

        return $axis->name;
    }

    /**
     * `winning()` re-expressed as the disjuncts of an ANTIJOIN — the shapes
     * whose ABSENCE makes an activity solo. Each returned closure narrows a
     * subquery already correlated to the activity; an activity is solo when
     * every one of them finds nothing.
     *
     * The rewrite rests on two equivalences, both exact:
     *
     * 1. `NOT EXISTS(P1 OR P2)` ≡ `NOT EXISTS(P1) AND NOT EXISTS(P2)`.
     *    Universally true, and what splits either mode's predicate in two.
     *
     * 2. In the live branch the second disjunct is
     *    `bucket = 'repeat' AND NOT EXISTS(w: winner = true)` — but it is
     *    only ever evaluated alongside the first, `NOT EXISTS(winner = true)`,
     *    which already guarantees this activity has no winner stamped
     *    anywhere. So the nested subquery is TRUE by construction here and
     *    drops out, leaving a bare `bucket = 'repeat'`. The nested lookup is
     *    load-bearing inside `winning()`, where a row is judged on its own;
     *    it is redundant only under the negation, which is why this lives
     *    apart from `winning()` rather than replacing it.
     *
     * Kept beside `winning()` deliberately: these two must agree, and a
     * reader changing one has to see the other. Any new disjunct in
     * `winning()` needs its mirror image here or activities start vanishing
     * from the read path — the one thing this package promises never happens.
     *
     * @return list<Closure(QueryBuilder): QueryBuilder>
     */
    protected function notSolo(): array
    {
        $groupings = $this->groupingModel()->getTable();

        if ($this->mode() === 'summary') {
            $bucket = $this->summaryBucket();

            return [
                fn (QueryBuilder $sub) => $sub->where("{$groupings}.bucket", $bucket),
            ];
        }

        return [
            fn (QueryBuilder $sub) => $sub->where("{$groupings}.winner", true),
            fn (QueryBuilder $sub) => $sub->where("{$groupings}.bucket", 'repeat'),
        ];
    }

    /**
     * Activities carrying no winning grouping row at all (legacy, imported,
     * or awaiting the trickle). Their presence here is what keeps graceful
     * degradation true: the read path never hides an activity.
     *
     * @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function soloStream(Carbon $now, ?array $cursor): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $query = $this->filteredActivities($now);

        // "Has no winning grouping row", SPLIT INTO ONE ANTIJOIN PER DISJUNCT
        // rather than one antijoin over `winning()`. See notSolo() for why the
        // split is exactly equivalent; this is the measured half of it.
        //
        // MEASURED ON MYSQL 8.4.11, 50k activities, page 1: 263ms -> 109ms,
        // taking the whole page from 689ms to 529ms (W103, 2026-09-09, see
        // docs/held/w103-groupstream.md). This query proves a NEGATIVE over
        // history, so it is at its most expensive when every activity IS
        // curated and it returns nothing — the healthy steady state, and the
        // read every page load pays for. The single `where($this->winning())`
        // form put an OR and a correlated subquery inside the antijoin, which
        // blocks a covering index: MySQL read ~5 grouping ROWS per activity
        // from the clustered index, 50,000 times. Split, each disjunct is a
        // covering index lookup on an existing index, and the first one that
        // matches short-circuits the rest.
        foreach ($this->notSolo() as $constraint) {
            $query->whereNotExists(fn (QueryBuilder $sub) => $constraint($sub
                ->selectRaw('1')
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")));
        }

        $query
            // Composite parents and members are never solo: the parent is
            // told by its cluster node, the members by their composite. In
            // the digest the parent IS the telling and takes its person's
            // row; one written before partition rows existed has none, and
            // reads solo here until the trickle or `--rehash` writes it.
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from("{$groupings} as composite_rows")
                ->whereColumn('composite_rows.activity_id', "{$activities}.id")
                ->where('composite_rows.bucket', 'composite')
                ->when($this->mode() === 'summary', fn (QueryBuilder $members) => $members->where('composite_rows.winner', true)))
            ->with(ActivityRoles::cachedRelations())
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->limit($this->limit + 1);

        if ($cursor !== null && $cursor['rank'] === self::RANK_SOLO) {
            $query->where(fn (ActivityBuilder $q) => $q
                ->where("{$activities}.published_at", '<', $cursor['latest'])
                ->orWhere(fn (ActivityBuilder $tie) => $tie
                    ->where("{$activities}.published_at", '=', $cursor['latest'])
                    ->where("{$activities}.id", '<', $cursor['id'])));
        } elseif ($cursor !== null) {
            // Every group at this timestamp is spent; solos in the tie remain.
            $query->where("{$activities}.published_at", '<=', $cursor['latest']);
        }

        return $query->get()->map(fn (Activity $activity) => FeedCandidate::solo(
            $this->normalizeTimestamp($activity->published_at),
            $activity,
        ));
    }

    /**
     * Phase 2 — members of the selected groups, newest first, capped per
     * group (per group and verb, `$byVerb`) by ROW_NUMBER() so one
     * 10k-member group cannot swamp a page.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return Collection<array-key, EloquentCollection<int, Activity>>
     */
    protected function fetchMembers(Carbon $now, Collection $groups, bool $byVerb = false): Collection
    {
        if ($groups->isEmpty()) {
            return Collection::make();
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        // A digest row caps per VERB, so a busy day's first 25 children
        // cannot all be one verb and leave the other phrases no sample.
        $grammar = $this->activityModel()->getConnection()->getQueryGrammar();
        $partition = sprintf(
            'row_number() over (partition by %s, %s%s order by %s desc, %s desc) as member_rank',
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket'),
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash'),
            $byVerb ? ', '.$grammar->wrapTable($activities).'.'.$grammar->wrap('verb') : '',
            $grammar->wrapTable($activities).'.'.$grammar->wrap('published_at'),
            $grammar->wrapTable($activities).'.'.$grammar->wrap('id'),
        );

        $ranked = $this->selectedGroupMembers($now, $groups)
            ->select([
                "{$activities}.*",
                "{$groupings}.bucket as group_bucket",
                "{$groupings}.hash as group_hash",
            ])
            ->selectRaw($partition);

        $rows = $this->activityModel()->getConnection()->query()
            ->fromSub($ranked, 'ranked')
            ->where('member_rank', '<=', $this->childrenLimit())
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $members = $this->activityModel()->newQuery()->hydrate($rows->all());

        $members->load(ActivityRoles::cachedRelations());

        return $members->groupBy(fn (Activity $activity) => $activity->group_bucket."\x1f".$activity->group_hash);
    }

    /**
     * TRUE distinct counts per role per selected group — the source of the
     * payload's `distinct` block. They cannot be derived from `children`,
     * which is capped: a 200-actor group would otherwise report "and 22
     * more". One aggregate query per role (7/page — acceptable; the Step 3
     * read model absorbs this someday), each a subquery of distinct
     * (group, role) rows because multi-column COUNT(DISTINCT …) is not
     * portable.
     *
     * The same rows count the tombstones among them (the payload's
     * `distinct_tombstoned`): a distinct entity is tombstoned when its type
     * is the tombstone alias, so it costs a SUM, not another query.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return array{distinct: array<string, array<string, int>>, tombstoned: array<string, array<string, int>>} groupKey => role => count
     */
    protected function countDistinctRoles(Carbon $now, Collection $groups): array
    {
        if ($groups->isEmpty()) {
            return ['distinct' => [], 'tombstoned' => []];
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $counts = [];
        $tombstoned = [];

        foreach (ActivityRoles::GROUPABLE as $role) {
            $distinct = $this->selectedGroupMembers($now, $groups)
                ->whereNotNull("{$activities}.{$role}_type")
                ->select([
                    "{$groupings}.bucket as group_bucket",
                    "{$groupings}.hash as group_hash",
                    "{$activities}.{$role}_type",
                    "{$activities}.{$role}_id",
                ])
                ->distinct()
                ->toBase();

            $rows = $this->activityModel()->getConnection()->query()
                ->fromSub($distinct, 'd')
                ->groupBy('group_bucket', 'group_hash')
                ->select(['group_bucket', 'group_hash'])
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when {$role}_type = ? then 1 else 0 end) as tombstoned", [FeedTombstone::MORPH_ALIAS])
                ->get();

            foreach ($rows as $row) {
                $key = $row->group_bucket."\x1f".$row->group_hash;
                $counts[$key][$role] = (int) $row->total;
                $tombstoned[$key][$role] = (int) $row->tombstoned;
            }
        }

        return ['distinct' => $counts, 'tombstoned' => $tombstoned];
    }

    /**
     * The filtered activities belonging to the selected groups, joined to
     * their winning grouping row.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return ActivityBuilder<Activity>
     */
    protected function selectedGroupMembers(Carbon $now, Collection $groups): ActivityBuilder
    {
        $groupings = $this->groupingModel()->getTable();

        return $this->winningMembers($now)
            ->where(function ($query) use ($groupings, $groups) {
                foreach ($groups as $group) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where("{$groupings}.bucket", $group->axis)
                        ->where("{$groupings}.hash", $group->hash));
                }
            });
    }

    /**
     * Every filtered activity joined to its winning grouping row(s) — the
     * membership relation both the page's phase-2 reads and the windowed
     * aggregate's ceiling exclusion are built on.
     *
     * @return ActivityBuilder<Activity>
     */
    protected function winningMembers(Carbon $now): ActivityBuilder
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        return $this->filteredActivities($now)
            ->join($groupings, fn (JoinClause $join) => $join
                ->on("{$groupings}.activity_id", '=', "{$activities}.id"))
            ->where($this->winning());
    }

    protected function groupKey(FeedCandidate $candidate): string
    {
        return $candidate->axis."\x1f".$candidate->hash;
    }

    protected function childrenLimit(): int
    {
        return (int) config('storyfeed.grouping.children_limit', 25);
    }

    /** @return ActivityBuilder<Activity> */
    protected function filteredActivities(Carbon $now): ActivityBuilder
    {
        return $this->activityModel()->newQuery()
            ->published($now)
            ->when($this->unresolvable, fn (ActivityBuilder $q) => $q->whereRaw('1 = 0'))
            ->when($this->actor, fn (ActivityBuilder $q, Model $m) => $q->actor($m))
            ->when($this->object, fn (ActivityBuilder $q, Model $m) => $q->object($m))
            ->when($this->target, fn (ActivityBuilder $q, Model $m) => $q->target($m))
            ->when($this->context, fn (ActivityBuilder $q, Model $m) => $q->context($m))
            ->when($this->involving, fn (ActivityBuilder $q, Model $m) => $q->involving($m))
            ->when($this->verb, fn (ActivityBuilder $q, string $verb) => $q->verb($verb))
            ->tap(fn (ActivityBuilder $q) => $this->applyConstraints($q));
    }

    /**
     * The caller's query() callbacks, plus the verb filter if there is one.
     *
     * The nesting is the whole point of this method existing, and it is
     * UNCONDITIONAL. AND binds tighter than OR, so a callback whose first move
     * is a top-level `orWhere` lands as a sibling of everything
     * `filteredActivities()` already built, and SQL reads the whole read as
     * `(published and involving and theirs) or (their other thing)` — an escape
     * from the publish gate and the requested scope that nobody wrote and the
     * resulting page cannot show you. Wrapping the callbacks in their own group
     * makes it impossible: whatever boolean shape they compose inside the
     * parens, the group as a whole AND-s against the scope.
     *
     * The verb filter is applied AFTER the group for the same reason — it must
     * AND against the group, not join it as a sibling.
     *
     * The rule has no exceptions on purpose. An earlier version grouped only
     * when a verb allowlist was present, which meant the safety of your `query()`
     * callback depended on whether some OTHER part of the feed happened to call
     * only()/except() — the kind of asymmetry you cannot hold in your head.
     *
     * @param  ActivityBuilder<Activity>  $query
     */
    protected function applyConstraints(ActivityBuilder $query): void
    {
        if ($this->callbacks !== []) {
            $query->where(fn (ActivityBuilder $group) => $this->applyCallbacks($group));
        }

        $this->verbFilter?->applyTo($query);
    }

    /**
     * Hand the candidate query to each `query()` callback, then make sure they
     * left it usable.
     *
     * A limit or offset here would truncate the candidate set BEFORE grouping,
     * curation and member counting see it — the hazard
     * `ActivityBuilder::involving()` warns about in its own comment. It is
     * refused rather than silently honoured, because the result would be a page
     * that looks fine and is wrong.
     *
     * Ordering is dropped instead of refused: this method returns an unordered
     * candidate set on purpose and each caller adds the ordering its stream and
     * cursor depend on, so a stray `orderBy` is meaningless rather than
     * mistaken.
     *
     * @param  ActivityBuilder<Activity>  $query
     */
    protected function applyCallbacks(ActivityBuilder $query): void
    {
        if ($this->callbacks === []) {
            return;
        }

        foreach ($this->callbacks as $callback) {
            $callback($query);
        }

        $base = $query->getQuery();

        if ($base->limit !== null || $base->offset !== null) {
            throw new InvalidArgumentException(
                'A query() callback set a limit or offset on the candidate activities, which '
                .'would truncate them before grouping and curation ran. Use FeedBuilder::limit() '
                .'to size the page instead.',
            );
        }

        $query->reorder();
    }

    /**
     * Cursor internals are NOT contract (docs/payload.md) — they encode the
     * position in the merged item stream, not a row offset.
     *
     * @return array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null
     */
    protected function cursorState(): ?array
    {
        $cursor = $this->decodedCursor();

        if ($cursor === null) {
            return null;
        }

        $parameters = $cursor->toArray();

        if (! isset($parameters['latest'], $parameters['rank'])) {
            return null;
        }

        // Normalized on the way in as well as out: a cursor minted before
        // timestamps carried microseconds says `12:00:00`, and the row it was
        // minted from now says `12:00:00.000000`. Same instant; SQLite compares
        // the text and would not agree.
        return [
            'latest' => $this->normalizeTimestamp($parameters['latest']),
            'rank' => (int) $parameters['rank'],
            'axis' => $parameters['axis'] ?? null,
            'hash' => $parameters['hash'] ?? null,
            'id' => $parameters['id'] ?? null,
        ];
    }

    protected function encodeCursor(?FeedCandidate $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        return (new Cursor([
            'latest' => $candidate->latest,
            'rank' => $this->rank($candidate),
            'axis' => $candidate->axis,
            'hash' => $candidate->hash,
            'id' => $candidate->activity?->getKey(),
        ]))->encode();
    }

    /**
     * Timestamps arrive as driver strings (aggregates) or Carbon instances
     * (models); both must compare identically in the merge and round-trip
     * through the cursor into a SQL bind.
     */
    protected function normalizeTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Chronology::stamp($value instanceof CarbonInterface ? $value : (string) $value);
    }

    protected function decodedCursor(): ?Cursor
    {
        return $this->cursor === null ? null : Cursor::fromEncoded($this->cursor);
    }

    protected function activityModel(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }

    protected function groupingModel(): Grouping
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return new $model;
    }
}
