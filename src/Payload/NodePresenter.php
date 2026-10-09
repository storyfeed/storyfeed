<?php

namespace Storyfeed\Payload;

use Closure;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Storyfeed\FeedContext;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityContextFactory;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\LinkResolver;
use Storyfeed\Support\ModelHydrator;
use Storyfeed\Support\TombstoneRules;
use Throwable;
use WeakMap;

/**
 * Builds Payload v1 nodes (docs/payload.md) from hydrated activities.
 *
 * Entities are self-describing: label/data/body come from the snapshot,
 * while the URL and media are regenerated live via the model's static
 * feedMedia() resolver — wrapped so one broken link never breaks the feed.
 * Missing snapshots degrade to placeholder entities; activities are never
 * withheld.
 */
class NodePresenter
{
    /**
     * The page's tombstones, by key, loaded in one query by forPage(). Null
     * off a page, where each is a single lookup; never shared between pages,
     * for the reason the identity map isn't.
     *
     * @var array<string, FeedTombstone|null>|null
     */
    protected ?array $tombstones = null;

    /** @var WeakMap<Snapshot, array<string, mixed>>|null */
    protected ?WeakMap $snapshotFields = null;

    /** @var array<class-string<Activity>, list<string>> */
    protected array $roleGetters = [];

    /** @var array<string, true> */
    protected array $roleColumns = [];

    /** @var array<string, bool> */
    protected array $outboundRoleCasts = [];

    /**
     * @param  string|null  $feed  the registered name of the feed this page was
     *                             read through, or null for an ad-hoc builder
     */
    public function __construct(
        protected StoryfeedManager $storyfeed,
        protected ?string $feed = null,
        protected ?ModelHydrator $hydrator = null,
        protected ?LinkResolver $links = null,
    ) {}

    /**
     * The same presenter, told which feed it is presenting.
     *
     * A copy rather than a setter because the presenter is resolved from the
     * container: were an app to bind it as a singleton, a setter would leak
     * one page's feed name into the next page rendered in the same process —
     * a queued digest rendering the reader feed after the editorial feed
     * would resolve editorial URLs. A copy cannot.
     */
    public function forFeed(?string $feed): static
    {
        $presenter = clone $this;
        $presenter->feed = $feed;

        return $presenter;
    }

    /**
     * The same presenter, holding a fresh identity map seeded with every
     * (type, id) the page carries — what lets FeedContext::model() load a
     * whole class in one query the first time any resolver asks for it.
     *
     * A copy, for the reason forFeed() is: an identity map that outlived the
     * build would hand one page's models to the next page rendered in the
     * same process, and a singleton-bound presenter would do exactly that.
     * Seeded from the loaded members, so a group's capped children and the
     * sample drawn from them are covered; nothing beyond the page is.
     *
     * A presenter that was never given a page still works — entity() falls
     * back to a private, unseeded map, which makes model() a single lookup.
     * Correct, only not amortised; the seam FeedPage::items() exists to close.
     *
     * The page's LinkResolver rides along for the same reason and with the
     * same lifetime: it is what makes a resolver that throws for a whole
     * class reported once for this page rather than once per entity on it
     * (issue #9), and a memo that outlived the page would report the second
     * page's failures not at all.
     *
     * @param  Collection<int, GroupSlice>  $slices
     */
    public function forPage(Collection $slices): static
    {
        $hydrator = new ModelHydrator;
        $tombstoneIds = [];

        foreach ($slices as $slice) {
            foreach ($slice->members as $activity) {
                $roles = $this->roleFields($activity);
                foreach (ActivityRoles::PAYLOAD as $role) {
                    $type = $roles["{$role}_type"] ?? null;
                    $id = $roles["{$role}_id"] ?? null;
                    $hydrator->seed($type, $id);

                    if ($type === FeedTombstone::MORPH_ALIAS && $id !== null) {
                        $tombstoneIds[(string) $id] = true;
                    }
                }
            }
        }

        $presenter = clone $this;
        $presenter->hydrator = $hydrator;
        $presenter->links = new LinkResolver;
        $presenter->tombstones = self::loadTombstones(array_keys($tombstoneIds));
        $presenter->snapshotFields = new WeakMap;

        return $presenter;
    }

    /** @return array<string, mixed> */
    public function node(GroupSlice $slice): array
    {
        return $slice->isGroup()
            ? $this->groupNode($slice)
            : $this->activityNode($slice->members->first());
    }

    /** @return array<string, mixed> */
    public function activityNode(Activity $activity): array
    {
        [$template, $headline] = $this->headline($activity);
        [$tombstoned, $redundant] = $this->tombstoneFact($activity);
        $type = $this->objectType($activity);
        [$missingTemplate, $missingHeadline] = $redundant
            ? $this->render($this->storyfeed->missingTemplate($type, $activity->verb), $activity, $type)
            : [null, null];

        return [
            'kind' => 'activity',
            'id' => $activity->uid,
            'verb' => $activity->verb,
            'published_at' => Chronology::iso($activity->published_at),
            // Additive (2026-10-09): the time range the activity describes,
            // AS2's `startTime` / `endTime`, beside `published_at` and never
            // instead of it. Either may be null on its own: a range open at
            // one end. See docs/payload.md, time ranges.
            'starts_at' => Chronology::iso($activity->starts_at),
            'ends_at' => Chronology::iso($activity->ends_at),
            'headline_template' => $template,
            'headline' => $headline,
            // Renamed from `icon` (2026-09-07, pre-freeze): a verb-resolved GLYPH
            // token, not Activity Streams' `icon` — which is an image and lives at
            // `entity.media.icon`. One word must not mean two things in one
            // document. See docs/payload.md, `glyph`.
            'glyph' => $this->storyfeed->icon($type, $activity->verb),
            // Additive (2026-09-09): the glyph's INTENT — an app-owned word
            // (`success`, `danger`, whatever the renderer's palette speaks)
            // resolved on the same ladder as the token but from its own
            // registry, so `*.finalize` can carry it once. A sibling key, not
            // a field inside `glyph`: `glyph` is a frozen string and every
            // renderer reads it as one. Null for every app that has not opted
            // in. Core names no intents and no colours; the AS2 document has
            // no term for it and never carries it. See docs/payload.md,
            // `glyph_intent`.
            'glyph_intent' => $this->storyfeed->glyphIntent($type, $activity->verb),
            ...$this->activityEntities($activity),
            'data' => $activity->data,
            // Additive (2026-09-23): the roles whose entity was deleted, and
            // whether one of them is constitutive for this verb, so the
            // activity is redundant as news though still true as history.
            // A fact, never wording: the renderer chooses between "Dana
            // placed a removed order" and "an order Dana placed was later
            // removed". See docs/payload.md, tombstones.
            'tombstoned' => $tombstoned,
            'redundant' => $redundant,
            // Additive (2026-09-23): what the verb says the activity reads as
            // once it is redundant (`->missingHeadline()`), the same pair as
            // `headline_template` / `headline`. Null unless `redundant` is
            // true and the verb declares one. `headline_template` never
            // swaps, so a renderer may show either reading, and one that
            // knows nothing of these keys is untouched. App-authored grammar,
            // like any headline: core still writes no wording for a
            // tombstone. See docs/payload.md, tombstones.
            'missing_headline_template' => $missingTemplate,
            'missing_headline' => $missingHeadline,
        ];
    }

    /**
     * Resolve [headline_template, headline] for an activity. String grammar
     * entries are frontend-tokenizable templates. A closure entry runs here,
     * and its result is read by what it contains: a string naming a role
     * token is a template (names stay tokens, so they stay links), and one
     * without is finished text in `headline`, with the template null.
     *
     * Optional segments (`[ with :target]`) are resolved against this
     * activity's roles, so the template that reaches the payload never
     * contains a bracket.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function headline(Activity $activity): array
    {
        // Inspect recorded identity, not the relation: an unresolved/deleted
        // participant is not a genuinely absent actor. Party identities stay normal.
        $type = $this->objectType($activity);
        $entry = $activity->actor_type === null && $activity->actor_id === null
            ? $this->storyfeed->actorlessTemplate($type, $activity->verb)
            : null;
        $entry ??= $this->storyfeed->template($type, $activity->verb);

        return $this->render($entry, $activity, $type);
    }

    /**
     * [template, finished text] for one grammar entry and one activity: a
     * closure runs, and optional segments resolve against its roles.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function render(string|Closure|null $entry, Activity $activity, ?string $type = null): array
    {
        if ($entry instanceof Closure) {
            try {
                $result = $entry(ActivityContextFactory::make($activity, $this->feed, $this->hydrator, $type));
                $entry = $result instanceof FeedHeadline ? $result->toTemplate() : (string) $result;
            } catch (Throwable $e) {
                report($e);

                return [null, null];
            }

            if (! FeedHeadline::hasRoleTokens($entry)) {
                return [null, $entry];
            }
        }

        if ($entry === null) {
            return [null, null];
        }

        return [
            FeedHeadline::resolveSegments(FeedHeadline::forCount($entry, 1), fn (string $role): bool => $activity->{"{$role}_type"} !== null),
            null,
        ];
    }

    /**
     * Resolve [headline_template, headline] for a group.
     *
     * Aggregate grammar is keyed "axis.verb" and adds the :actors / :count /
     * :others tokens. Without an entry the group falls back to the head
     * member's SINGULAR template — but only when that template's tokens are
     * all pinned by the axis, or the noun rung can honestly pluralise the
     * ones that are not. An unchecked singular fallback is the lie class
     * arriving through the back door: "Bob Callahan uploaded — to Analytics
     * Dashboard" rendered over ten uploads by two people. Unsafe fallbacks
     * yield a null template — the renderer's
     * generic group treatment beats a wrong sentence. `storyfeed:doctor` and
     * HeadlineCoverage surface the missing entry.
     *
     * @param  array<string, int>  $distinct  the node's published distinct
     *                                        block, keyed by PLURAL role
     * @return array{0: string|null, 1: string|null}
     */
    protected function aggregateHeadline(GroupSlice $slice, array $distinct): array
    {
        $head = $slice->members->first();

        // The object type qualifies the key only when the axis pins it —
        // otherwise the group may hold several object types and the key would
        // name whichever member came first.
        $objectType = $this->storyfeed->axis((string) $slice->axis)?->pinsType('object') === true && $head !== null
            ? $this->objectType($head)
            : null;

        $entry = $this->storyfeed->aggregateTemplate((string) $slice->axis, (string) $head?->verb, $objectType);

        if ($entry === null) {
            return $this->safeSingularFallback($slice, $distinct);
        }

        if ($entry instanceof Closure) {
            try {
                return [null, (string) $entry($slice)];
            } catch (Throwable $e) {
                report($e);

                return [null, null];
            }
        }

        return [$entry, null];
    }

    /**
     * The head member's singular template, admitted when every token it uses
     * is homogeneous across the group (pinned by the axis) — and, failing
     * that, handed to the noun rung, which can still rescue it by turning an
     * unpinned role into a count of things.
     *
     * Closure entries pre-render from ONE member and cannot be inspected,
     * so they are never safe for a group.
     *
     * @param  array<string, int>  $distinct
     * @return array{0: string|null, 1: string|null}
     */
    protected function safeSingularFallback(GroupSlice $slice, array $distinct): array
    {
        $first = $slice->members->first();

        $entry = $this->storyfeed->template($this->objectType($first), $first->verb);

        if (! is_string($entry)) {
            return [null, null];
        }

        $pinned = $this->storyfeed->aggregateTokens((string) $slice->axis) ?? [];

        preg_match_all('/:[a-z]+/', $entry, $matches);

        $unpinned = array_values(array_diff(array_unique($matches[0]), $pinned));

        if ($unpinned === []) {
            return [$entry, null];
        }

        return $this->pluralisedFallback($slice, $entry, $unpinned, $distinct);
    }

    /**
     * THE NOUN RUNG (2026-08-27).
     *
     * An unpinned role is not unknowable — it is PLURALISABLE. The repeat
     * axis pins actor, verb, object TYPE and target, so ":actor reworded
     * :object in the clause library" over nine activities was thrown away
     * whole for the sake of one token, and the reader got "Clause reworded ·
     * 9 times". Every member agrees that Jasper reworded SOMETHING; the
     * something is a plurality of clauses; so the sentence is available and
     * true:
     *
     *     ":actor reworded clauses in the clause library"
     *
     * WHAT COMES BACK IS A TEMPLATE, NOT A HEADLINE. Only the unpinned
     * tokens are substituted; `:actor` stays a token, because it is a real
     * entity the renderer turns into a LINK and pre-rendering it here would
     * destroy that. It rides home through the existing [$template, null]
     * channel: no payload shape change, no new node key, no renderer change.
     *
     * THE COUNT IS `distinct`, NEVER THE MEMBER COUNT — AND IT IS NOT
     * PRINTED (2026-09-05). The rung shipped saying "7 clauses", the most
     * truthful number available: nine activities across seven clauses, and
     * "9 clauses" would assert two clauses into existence. Then production
     * put "updated 2 terms sheets" directly above a disclosure reading "Show
     * all 5", and two readers who knew the mechanism both read it as a bug.
     * Nothing on the screen says one number counts sheets and the other
     * counts acts. On the same screen the fully-pinned row — "updated
     * Acme Retainer — Terms of Engagement" over "Show all 9" — read
     * perfectly, with no number in the sentence at all. So the distinct
     * count survives only to SELECT THE PLURAL FORM (FeedNoun::form()), which is
     * still a truth about the world and still locale-sensitive, and the
     * sentence carries no number for the disclosure to disagree with.
     * Authored templates are untouched: an author who writes `:count` gets
     * the act count the renderer already formats, at the end of the clause
     * where an author would put it. A substitution mid-sentence cannot.
     *
     * WHERE IT DECLINES, AND WHY EACH REFUSAL IS THE RIGHT ANSWER:
     *
     *  - The count is 1. Then the role is shared in fact, and the renderer
     *    can NAME it from `sample` (FeedPresenter::groupRole). "1 clause"
     *    where the clause could have been named is a regression, not a
     *    fallback, so the token is left alone rather than substituted.
     *  - The count is 0. The role is ABSENT, not plural. A template naming a
     *    role its activities never carry is an authoring bug that the
     *    `roles` doctor check exists to surface; "0 items" would paper over
     *    precisely what it is watching for.
     *  - The axis does not pin the role's KIND. Two objects in one group can
     *    then be a clause and a spreadsheet, and "7 clauses" would be a lie
     *    of kind in place of a lie of number. This is also what keeps the
     *    rung off the `actors` axis, where "3 items commented on Concur" is
     *    plainly worse than the label it would replace — that group wants an
     *    AUTHORED aggregate template naming `:actors`, and should keep
     *    reading as unfinished until it gets one.
     *  - The token is not a role at all (`:verb`, or anything invented).
     *    Nothing can be counted, so nothing is claimed.
     *  - The slice carries no true distinct counts. The in-page count is
     *    capped at `grouping.children_limit` and would understate; a floor
     *    is fine for a sample list that says "and N others", and not fine
     *    for a number the sentence asserts outright.
     *
     * Failing any of these, the rung returns null and the ladder falls to
     * the verb label. That is the bar the OUTPUT has to clear: a bland
     * sentence reads as finished, while "Clause reworded · 9 times" reads as
     * unfinished, so a sentence worse than the label is worse than nothing.
     *
     * @param  list<string>  $unpinned
     * @param  array<string, int>  $distinct
     * @return array{0: string|null, 1: string|null}
     */
    protected function pluralisedFallback(GroupSlice $slice, string $entry, array $unpinned, array $distinct): array
    {
        if ($slice->distinct === []) {
            return [null, null];
        }

        $first = $slice->members->first();
        $axis = (string) $slice->axis;
        $phrases = [];

        foreach ($unpinned as $token) {
            $role = ltrim($token, ':');

            if (! isset(self::GROUP_ROLES[$role])) {
                return [null, null];
            }

            $count = $distinct[self::GROUP_ROLES[$role][0]] ?? 0;

            if ($count === 0) {
                return [null, null];
            }

            if ($count === 1) {
                continue;
            }

            if (! $this->storyfeed->pinsType($axis, $role)) {
                return [null, null];
            }

            // The type is pinned by construction, so the head member's alias
            // is every member's alias — the licence for one noun to speak
            // for all of them. The count picks the form; it is never printed.
            $phrase = FeedNoun::form(
                $this->storyfeed->noun($first->{"{$role}_type"}, (string) $first->verb),
                $count,
            );

            // A noun that can look like a token would be re-substituted by
            // the renderer, which tokenises the string we hand it. Refuse
            // rather than mangle the author's words.
            if (preg_match('/:[a-z]+/', $phrase) === 1) {
                return [null, null];
            }

            $phrases[$token] = $phrase;
        }

        foreach ($phrases as $token => $phrase) {
            // `(?![a-z])` so substituting :object cannot eat the ":object"
            // inside ":objects", and a callback so a phrase containing `$`
            // is never read as a backreference.
            $entry = (string) preg_replace_callback(
                '/'.preg_quote($token, '/').'(?![a-z])/',
                fn (): string => $phrase,
                $entry,
            );
        }

        return [$entry, null];
    }

    /** role => [plural sample key, snapshot relation] */
    protected const GROUP_ROLES = [
        'actor' => ['actors', 'cachedActor'],
        'object' => ['objects', 'cachedObject'],
        'target' => ['targets', 'cachedTarget'],
        'context' => ['contexts', 'cachedContext'],
        'origin' => ['origins', 'cachedOrigin'],
        'result' => ['results', 'cachedResult'],
        'instrument' => ['instruments', 'cachedInstrument'],
    ];

    /** @return array<string, mixed> */
    public function groupNode(GroupSlice $slice): array
    {
        $members = $slice->members;
        $first = $members->first();

        [$sample, $distinct, $distinctTombstoned] = $this->samples($members, $slice->distinct, $slice->tombstoned);

        [$template, $headline] = $this->aggregateHeadline($slice, $distinct);
        $template = $template === null ? null : FeedHeadline::forCount($template, $slice->count);

        // Optional segments: a role no member holds is empty for the group.
        $template = $template === null ? null : FeedHeadline::resolveSegments(
            $template,
            fn (string $role): bool => ($distinct[self::GROUP_ROLES[$role][0]] ?? 0) > 0,
        );

        /*
         * PINNED ROLES ALSO ANSWER THE SINGULAR TOKEN (2026-08-26).
         *
         * A group used to carry roles ONLY as sample lists, on the sound
         * reasoning that a group is many activities. But an axis that PINS a
         * role collapses it to exactly one entity by construction — and
         * `aggregateTokens()` already says so, which is how
         * `safeSingularFallback()` admits a singular template containing
         * `:actor` for a repeat group in the first place.
         *
         * So the registry promised a token the node did not carry, and every
         * renderer had to discover that for itself. Two did: the Vue renderer
         * quietly reconstructs the singular from `sample[0]`, and an
         * admin-panel renderer rendered ":actor" as "Someone" — a shrug with the
         * authority of a fact — on a vault row summarising client link opens.
         * A promise the payload does not keep is the payload's bug.
         *
         * ADDITIVE: these keys are new on group nodes and unchanged on
         * activity nodes, so a renderer that ignores them behaves exactly as
         * before. The guard is deliberately belt-and-braces — pinned by the
         * registry AND one distinct entity in fact — because a custom axis
         * declares its own pins and a mis-declared one must degrade to the
         * list rather than name one member for all of them.
         */
        $pinnedTokens = $this->storyfeed->aggregateTokens((string) $slice->axis) ?? [];
        $singulars = [];

        foreach (self::GROUP_ROLES as $role => [$key, $relation]) {
            $singulars[$role] = in_array(":{$role}", $pinnedTokens, true)
                && count($sample[$key]) === 1
                && $distinct[$key] === 1
                    ? $sample[$key][0]
                    : null;
        }

        $children = $members->map(fn (Activity $a) => $this->activityNode($a))->values()->all();

        [$tombstoned, $redundant] = $this->groupTombstoneFact($slice, $children, $distinct, $distinctTombstoned);

        // Every inferred Live row names one verb.
        $verb = $first->verb;

        $node = [
            'kind' => 'group',
            // Namespaced and versioned across axes and persisted burst hashes.
            'id' => 'grp_'.sha1("v1\x1f{$slice->axis}\x1f{$slice->hash}"),
            'axis' => $slice->axis,
            'count' => $slice->count,
            'verb' => $verb,
            'published_at' => Chronology::iso($first->published_at),
            'headline_template' => $template,
            'headline' => $headline,
            'glyph' => $this->storyfeed->icon($this->objectType($first), $verb),
            'glyph_intent' => $this->storyfeed->glyphIntent($this->objectType($first), $verb),
            ...$singulars,
            'sample' => $sample,
            'distinct' => $distinct,
            'children' => $children,
            'children_truncated' => $slice->count > count($children),
            // Additive (2026-09-23): the activity node's tombstone fact, for
            // the group as a whole, and how many of each role's distinct
            // entities are tombstones, keyed as `distinct` is, so "5 orders,
            // 2 since removed" is written without guessing.
            'tombstoned' => $tombstoned,
            'redundant' => $redundant,
            'distinct_tombstoned' => $distinctTombstoned,
        ];

        return $node;
    }

    /**
     * Every role's sample (distinct entities from the loaded members, each
     * role capped by `grouping.sample_limits`, default 3) and its true
     * distinct counts, floored by the in-page count when a caller built
     * the slice without them.
     *
     * A role the axis pins collapses to exactly one entry BY CONSTRUCTION —
     * all members share it — so no axis-conditional logic exists here, and
     * the collapsed dimensions ("which projects? which tasks?") are finally
     * nameable via the plural tokens.
     *
     * @param  Collection<int, Activity>  $members
     * @param  array<string, int>  $distinct  keyed by role
     * @param  array<string, int>  $tombstoned  keyed by role
     * @return array{0: array<string, list<array<string, mixed>|null>>, 1: array<string, int>, 2: array<string, int>}
     */
    protected function samples(Collection $members, array $distinct, array $tombstoned): array
    {
        $sample = [];
        $counts = [];
        $distinctTombstoned = [];

        $identities = [];
        foreach ($members as $member) {
            $roles = $this->roleFields($member);
            foreach (self::GROUP_ROLES as $role => [$key, $relation]) {
                $type = $roles["{$role}_type"] ?? null;
                if ($type === null) {
                    continue;
                }
                $id = $roles["{$role}_id"] ?? null;
                $identity = $type.':'.$id;
                if (isset($identities[$role]['seen'][$identity])) {
                    continue;
                }
                $identities[$role]['seen'][$identity] = true;
                $identities[$role][$type === FeedTombstone::MORPH_ALIAS ? 'removed' : 'live'][] = [$member, $type, $id];
            }
        }

        foreach (self::GROUP_ROLES as $role => [$key, $relation]) {
            $seen = $identities[$role]['seen'] ?? [];
            $live = $identities[$role]['live'] ?? [];
            $removed = $identities[$role]['removed'] ?? [];
            $limit = config("storyfeed.grouping.sample_limits.{$role}", 3);

            // Live entities first, tombstones after, each in member order:
            // "Dana, Sam and a former customer" over "a former customer, a
            // former customer and Dana". Curation, not contract.
            $sample[$key] = array_map(
                fn (array $entry) => $this->entity($entry[1], $entry[2], $entry[0]->{$relation}),
                array_slice([...$live, ...$removed], 0, is_int($limit) && $limit > 0 ? $limit : 3),
            );

            // True totals from the aggregate query; the in-page unique count
            // is the floor when a caller built the slice without them.
            $counts[$key] = max($distinct[$role] ?? 0, count($seen));
            $distinctTombstoned[$key] = max(
                $tombstoned[$role] ?? 0,
                count($removed),
            );
        }

        return [$sample, $counts, $distinctTombstoned];
    }

    /** @return array<string, mixed>|null */
    protected function entity(?string $type, int|string|null $id, ?Snapshot $snapshot): ?array
    {
        if ($type === null) {
            return null;
        }

        $data = $this->snapshotJson($snapshot, 'data') ?? [];

        // No snapshot ⇒ no link regeneration: the contract promises degraded
        // entities arrive with url: null, and calling the app's resolver
        // with empty data makes every naive implementation warn.
        $links = $this->links ?? new LinkResolver;
        $link = $snapshot === null ? null : $links->resolve(new FeedContext(
            type: $type,
            key: $id,
            label: $this->snapshotPlain($snapshot, 'label'),
            data: $data,
            feed: $this->feed,
            hydrator: $this->hydrator ?? new ModelHydrator,
            routeKey: $this->snapshotJson($snapshot, 'meta')['route_key'] ?? null,
        ));

        return [
            'type' => $type,
            'id' => $id === null ? null : (string) $id,
            'label' => $link->label ?? $this->snapshotPlain($snapshot, 'label'),
            'url' => $link?->href(),
            'attributes' => $link->attributes ?? [],
            'modal' => $link->modal ?? false,
            'data' => $data,
            // Additive (2026-09-05): the typed image slots, or null. `url`
            // above stays the string it was frozen as; when the resource
            // itself is an image its dimensions ride here as `media.url`.
            'media' => $link?->media(),
            // Omit only absent body fields: old snapshots keep their shape,
            // while an explicitly empty string remains authored content. The
            // body: what the snapshot stored, then what the resolver resolved.
            // Stored first because it is the entity as the app decided it, and
            // resolved second because it is the entity as it stands right now.
            // ORDER IS NOT CONTRACT beyond that — arrangement is a renderer's,
            // and a renderer that draws them another way is not wrong. A
            // resolved body that throws is reported and left out; the stored
            // body and the rest of the entity still arrive.
            'body' => self::bodyOrNull([...($this->snapshotJson($snapshot, 'body') ?? []), ...$links->body($link, $type)]),
            // Additive (2026-09-23): what a deleted entity left behind, or
            // null. Distinct from DEGRADED (a live entity with no snapshot
            // yet: `label: null`, `tombstone: null`) and from ANONYMOUS (no
            // entity at all: the role is null). See docs/payload.md.
            'tombstone' => $type === FeedTombstone::MORPH_ALIAS && $id !== null
                ? $this->tombstone($id)?->toPayload()
                : null,
            ...array_filter([
                'content' => $this->snapshotPlain($snapshot, 'content'),
                'mediaType' => $this->snapshotPlain($snapshot, 'media_type'),
                'attributedTo' => $this->snapshotPlain($snapshot, 'attributed_to'),
            ], fn ($value) => $value !== null),
        ];
    }

    /** @return array<string, array<string, mixed>|null> */
    protected function activityEntities(Activity $activity): array
    {
        $roles = $this->roleFields($activity);
        $entities = [];
        foreach (ActivityRoles::PAYLOAD as $role) {
            $type = $roles["{$role}_type"] ?? null;
            $entities[$role] = $type === null ? null : $this->entity(
                $type, $roles["{$role}_id"] ?? null, $activity->{'cached'.ucfirst($role)},
            );
        }

        return $entities;
    }

    /**
     * Plain recorded role identities need no Eloquent cast dispatch. Read
     * their current attributes together; custom accessors/casts still go
     * through the model for every read. Only accessor metadata is remembered.
     *
     * @return array<string, mixed>
     */
    protected function roleFields(Activity $activity): array
    {
        $class = $activity::class;
        if ($this->roleColumns === []) {
            foreach (ActivityRoles::PAYLOAD as $role) {
                $this->roleColumns["{$role}_type"] = true;
                $this->roleColumns["{$role}_id"] = true;
            }
        }
        $columns = $this->roleColumns;
        if (! isset($this->roleGetters[$class])) {
            $customRead = (new \ReflectionMethod($class, 'getAttribute'))->getDeclaringClass()->getName() !== Model::class
                || (new \ReflectionMethod($class, 'getAttributeValue'))->getDeclaringClass()->getName() !== Model::class
                || (new \ReflectionMethod($class, 'getAttributeFromArray'))->getDeclaringClass()->getName() !== Model::class;
            $this->roleGetters[$class] = array_values(array_filter(array_keys($columns),
                fn (string $column) => $customRead || $activity->hasAnyGetMutator($column)));
        }
        $attributes = $activity->getAttributes();
        $getters = $this->roleGetters[$class];
        foreach (array_intersect_key($activity->getCasts(), $columns) as $column => $cast) {
            $outbound = $this->outboundRoleCasts[$cast] ??= ! is_subclass_of(
                explode(':', $cast, 2)[0], CastsInboundAttributes::class,
            );
            if ($outbound) {
                $getters[] = $column;
            }
        }
        foreach (array_unique($getters) as $column) {
            $attributes[$column] = $activity->{$column};
        }

        return $attributes;
    }

    /**
     * Current plain snapshot values; custom models and casts keep their reader.
     *
     * @return string|null
     */
    protected function snapshotPlain(?Snapshot $snapshot, string $field): mixed
    {
        if ($snapshot === null) {
            return null;
        }
        if ($snapshot::class === Snapshot::class && ! array_key_exists($field, $snapshot->getCasts())) {
            $attributes = $snapshot->getAttributes();
            if (array_key_exists($field, $attributes)) {
                return $attributes[$field];
            }
        }

        return $snapshot->{$field};
    }

    /**
     * Decode only the JSON slot being read, at its original point in entity
     * construction. Resolver calls stay fresh and may change a later slot.
     * Raw values invalidate the memo; custom snapshot models retain getters.
     * Object identity preserves separate hydration versions of the same id.
     *
     * @return array<array-key, mixed>|null
     */
    protected function snapshotJson(?Snapshot $snapshot, string $field): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        if ($this->snapshotFields === null || $snapshot::class !== Snapshot::class
            || ($snapshot->getCasts()[$field] ?? null) !== 'array') {
            return $snapshot->{$field};
        }
        $raw = $snapshot->getAttributes()[$field] ?? null;
        $fields = $this->snapshotFields[$snapshot] ?? [];
        if (isset($fields[$field]) && $fields[$field]['raw'] === $raw) {
            return $fields[$field]['value'];
        }
        $value = $snapshot->{$field};
        $fields[$field] = ['raw' => $raw, 'value' => $value];
        $this->snapshotFields[$snapshot] = $fields;

        return $value;
    }

    /**
     * The object type the registries are asked about: for a tombstoned
     * object, the deleted model's own alias, so `order.place` still finds
     * its headline, glyph and tombstone rule once the order is gone.
     */
    protected function objectType(Activity $activity): ?string
    {
        if ($activity->object_type === FeedTombstone::MORPH_ALIAS && $activity->object_id !== null) {
            return $this->tombstone($activity->object_id)?->formerType() ?? $activity->object_type;
        }

        return $activity->object_type;
    }

    /**
     * [the tombstoned roles, whether one is constitutive] for one activity.
     *
     * @return array{0: list<string>, 1: bool}
     */
    protected function tombstoneFact(Activity $activity): array
    {
        $roles = $this->roleFields($activity);
        $tombstoned = array_values(array_filter(
            ActivityRoles::PAYLOAD,
            fn (string $role): bool => ($roles["{$role}_type"] ?? null) === FeedTombstone::MORPH_ALIAS,
        ));

        if ($tombstoned === []) {
            return [[], false];
        }

        $constitutive = app(TombstoneRules::class)->constitutiveRoles($this->objectType($activity), (string) $activity->verb);

        return [$tombstoned, array_intersect($tombstoned, $constitutive) !== []];
    }

    /**
     * The same fact for a group. `tombstoned` names every role with a
     * tombstone among the group's distinct entities; `redundant` holds only
     * when the whole group is: every loaded member is redundant, and some
     * constitutive role is tombstoned for EVERY distinct entity it holds,
     * which the true counts can vouch for beyond the loaded members.
     *
     * @param  list<array<string, mixed>>  $children
     * @param  array<string, int>  $distinct
     * @param  array<string, int>  $distinctTombstoned
     * @return array{0: list<string>, 1: bool}
     */
    protected function groupTombstoneFact(GroupSlice $slice, array $children, array $distinct, array $distinctTombstoned): array
    {
        $tombstoned = [];

        foreach (self::GROUP_ROLES as $role => [$key]) {
            if (($distinctTombstoned[$key] ?? 0) > 0) {
                $tombstoned[] = $role;
            }
        }

        if ($tombstoned === [] || $children === [] || in_array(false, array_column($children, 'redundant'), true)) {
            return [$tombstoned, false];
        }

        $first = $slice->members->first();
        $constitutive = app(TombstoneRules::class)->constitutiveRoles($this->objectType($first), (string) $first->verb);

        foreach ($constitutive as $role) {
            $key = self::GROUP_ROLES[$role][0] ?? null;

            if ($key !== null && ($distinct[$key] ?? 0) > 0 && ($distinctTombstoned[$key] ?? 0) === $distinct[$key]) {
                return [$tombstoned, true];
            }
        }

        return [$tombstoned, false];
    }

    /** One tombstone, from the page's map when there is one. */
    protected function tombstone(int|string $id): ?FeedTombstone
    {
        $id = (string) $id;

        if ($this->tombstones !== null && array_key_exists($id, $this->tombstones)) {
            return $this->tombstones[$id];
        }

        return self::loadTombstones([$id])[$id] ?? null;
    }

    /**
     * Every tombstone with one of these keys, in one query; a key with no
     * row maps to null. A failure is reported and every key reads as
     * missing: the entity still renders, with `tombstone: null`.
     *
     * @param  list<int|string>  $ids
     * @return array<string, FeedTombstone|null>
     */
    protected static function loadTombstones(array $ids): array
    {
        $map = array_fill_keys(array_map(strval(...), $ids), null);

        if ($map === []) {
            return [];
        }

        try {
            $model = config('storyfeed.models.tombstone', FeedTombstone::class);

            foreach ($model::query()->whereKey(array_keys($map))->get() as $tombstone) {
                $map[(string) $tombstone->getKey()] = $tombstone;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $map;
    }

    /**
     * Null rather than an empty list, which is the shape this contract uses
     * for every addition: a nullable sibling present on every node, null until
     * the app says otherwise. `media` reads the same way, and a renderer that
     * already writes `?? []` for one writes it for both.
     *
     * @param  list<array<string, mixed>>  $forms
     * @return list<array<string, mixed>>|null
     */
    private static function bodyOrNull(array $forms): ?array
    {
        return $forms === [] ? null : $forms;
    }
}
