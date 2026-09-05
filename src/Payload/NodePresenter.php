<?php

namespace Storyfeed\Payload;

use Closure;
use Illuminate\Support\Collection;
use Storyfeed\FeedContext;
use Storyfeed\FeedNoun;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\LinkResolver;
use Storyfeed\Support\ModelHydrator;
use Throwable;

/**
 * Builds Payload v1 nodes (docs/payload.md) from hydrated activities.
 *
 * Entities are self-describing: label/component/data come from the snapshot,
 * while the URL and media are regenerated live via the model's static
 * feedMedia() resolver — wrapped so one broken link never breaks the feed.
 * Missing snapshots degrade to placeholder entities; activities are never
 * withheld.
 */
class NodePresenter
{
    /**
     * @param  string|null  $feed  the registered name of the feed this page was
     *                             read through, or null for an ad-hoc builder
     */
    public function __construct(
        protected StoryfeedManager $storyfeed,
        protected ?string $feed = null,
        protected ?ModelHydrator $hydrator = null,
    ) {}

    /**
     * The same presenter, told which feed it is presenting.
     *
     * A copy rather than a setter because the presenter is resolved from the
     * container: were an app to bind it as a singleton, a setter would leak
     * one page's feed name into the next page rendered in the same process —
     * a queued digest rendering the customer feed after the kitchen feed
     * would mint kitchen URLs. A copy cannot.
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
     * exemplars drawn from them are covered; nothing beyond the page is.
     *
     * A presenter that was never given a page still works — entity() falls
     * back to a private, unseeded map, which makes model() a single lookup.
     * Correct, only not amortised; the seam FeedPage::items() exists to close.
     *
     * @param  Collection<int, GroupSlice>  $slices
     */
    public function forPage(Collection $slices): static
    {
        $hydrator = new ModelHydrator;

        foreach ($slices as $slice) {
            foreach ($slice->members as $activity) {
                foreach (array_keys(self::GROUP_ROLES) as $role) {
                    $hydrator->seed($activity->{"{$role}_type"}, $activity->{"{$role}_id"});
                }
            }
        }

        $presenter = clone $this;
        $presenter->hydrator = $hydrator;

        return $presenter;
    }

    public function node(GroupSlice $slice): array
    {
        return $slice->isGroup()
            ? $this->groupNode($slice)
            : $this->activityNode($slice->members->first());
    }

    public function activityNode(Activity $activity): array
    {
        [$template, $headline] = $this->headline($activity);

        return [
            'kind' => 'activity',
            'id' => $activity->uid,
            'verb' => $activity->verb,
            'published_at' => $activity->published_at?->toISOString(),
            'headline_template' => $template,
            'headline' => $headline,
            'icon' => $this->storyfeed->icon($activity->object_type, $activity->verb),
            'actor' => $this->entity($activity->actor_type, $activity->actor_id, $activity->cachedActor),
            'object' => $this->entity($activity->object_type, $activity->object_id, $activity->cachedObject),
            'target' => $this->entity($activity->target_type, $activity->target_id, $activity->cachedTarget),
            'context' => $this->entity($activity->context_type, $activity->context_id, $activity->cachedContext),
            'data' => $activity->data,
        ];
    }

    /**
     * Resolve [headline_template, headline] for an activity. String grammar
     * entries are frontend-tokenizable templates; closure entries pre-render
     * a headline server-side (template stays null, by design).
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function headline(Activity $activity): array
    {
        $entry = $this->storyfeed->template($activity->object_type, $activity->verb);

        if ($entry instanceof Closure) {
            try {
                return [null, (string) $entry($activity)];
            } catch (Throwable $e) {
                report($e);

                return [null, null];
            }
        }

        return [$entry, null];
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
     * Dashboard" rendered over ten uploads by two people (found live by the
     * Newsroom). Unsafe fallbacks yield a null template — the renderer's
     * generic group treatment beats a wrong sentence. `storyfeed:doctor` and
     * GrammarCoverage surface the missing entry.
     *
     * @param  array<string, int>  $distinct  the node's published distinct
     *                                        block, keyed by PLURAL role
     * @return array{0: string|null, 1: string|null}
     */
    protected function aggregateHeadline(GroupSlice $slice, array $distinct): array
    {
        $entry = $this->storyfeed->aggregateTemplate($slice->axis, (string) $slice->members->first()?->verb);

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

        $entry = $this->storyfeed->template($first->object_type, $first->verb);

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
     *    can NAME it from `exemplars` (FeedPresenter::groupRole). "1 clause"
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
     *    is fine for an exemplar list that says "and N others", and not fine
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

    /** role => [plural exemplars key, snapshot relation] */
    protected const GROUP_ROLES = [
        'actor' => ['actors', 'cachedActor'],
        'object' => ['objects', 'cachedObject'],
        'target' => ['targets', 'cachedTarget'],
        'context' => ['contexts', 'cachedContext'],
    ];

    public function groupNode(GroupSlice $slice): array
    {
        $members = $slice->members;
        $first = $members->first();

        // UNIFORM exemplars (2026-08-12): every role is a list of up to 3
        // distinct entities drawn from the loaded (capped) members. A role
        // the axis pins collapses to exactly one entry BY CONSTRUCTION —
        // all members share it — so no axis-conditional logic exists here,
        // and the collapsed dimensions ("which projects? which tasks?")
        // are finally nameable via the plural tokens.
        $exemplars = [];
        $distinct = [];

        foreach (self::GROUP_ROLES as $role => [$key, $relation]) {
            $unique = $members
                ->filter(fn (Activity $a) => $a->{"{$role}_type"} !== null)
                ->unique(fn (Activity $a) => $a->{"{$role}_type"}.':'.$a->{"{$role}_id"})
                ->values();

            $exemplars[$key] = $unique
                ->take(3)
                ->map(fn (Activity $a) => $this->entity($a->{"{$role}_type"}, $a->{"{$role}_id"}, $a->{$relation}))
                ->all();

            // True totals from the aggregate query; the in-page unique count
            // is the floor when a caller built the slice without them.
            $distinct[$key] = max($slice->distinct[$role] ?? 0, $unique->count());
        }

        [$template, $headline] = $this->aggregateHeadline($slice, $distinct);

        /*
         * PINNED ROLES ALSO ANSWER THE SINGULAR TOKEN (2026-08-26).
         *
         * A group used to carry roles ONLY as exemplar lists, on the sound
         * reasoning that a group is many activities. But an axis that PINS a
         * role collapses it to exactly one entity by construction — and
         * `aggregateTokens()` already says so, which is how
         * `safeSingularFallback()` admits a singular template containing
         * `:actor` for a repeat group in the first place.
         *
         * So the registry promised a token the node did not carry, and every
         * renderer had to discover that for itself. Two did: the Vue renderer
         * quietly reconstructs the singular from `exemplars[0]`, and the
         * Filament adapter rendered ":actor" as "Someone" — a shrug with the
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
                && count($exemplars[$key]) === 1
                && $distinct[$key] === 1
                    ? $exemplars[$key][0]
                    : null;
        }

        $children = $members->map(fn (Activity $a) => $this->activityNode($a))->values()->all();

        return [
            'kind' => 'group',
            // Namespaced and versioned: the digest must not collide across
            // axes once a group can win on more than `repeat`.
            'id' => 'grp_'.sha1("v1\x1f{$slice->axis}\x1f{$slice->hash}"),
            'axis' => $slice->axis,
            'count' => $slice->count,
            'verb' => $first->verb,
            'published_at' => $first->published_at?->toISOString(),
            'headline_template' => $template,
            'headline' => $headline,
            'icon' => $this->storyfeed->icon($first->object_type, $first->verb),
            ...$singulars,
            'exemplars' => $exemplars,
            'distinct' => $distinct,
            'children' => $children,
            'children_truncated' => $slice->count > count($children),
        ];
    }

    protected function entity(?string $type, int|string|null $id, ?Snapshot $snapshot): ?array
    {
        if ($type === null) {
            return null;
        }

        $data = $snapshot->data ?? [];

        // No snapshot ⇒ no link regeneration: the contract promises degraded
        // entities arrive with url: null, and calling the app's resolver
        // with empty data makes every naive implementation warn.
        $link = $snapshot === null ? null : LinkResolver::resolve(new FeedContext(
            type: $type,
            id: $id,
            label: $snapshot->label,
            data: $data,
            feed: $this->feed,
            hydrator: $this->hydrator ?? new ModelHydrator,
        ));

        return [
            'type' => $type,
            'id' => $id === null ? null : (string) $id,
            'label' => $link->label ?? $snapshot?->label,
            'url' => $link?->href(),
            'attributes' => $link->attributes ?? [],
            'modal' => $link->modal ?? false,
            'component' => $snapshot?->component,
            'data' => $data,
            // Additive (2026-09-05): the typed image slots, or null. `url`
            // above stays the string it was frozen as; when the resource
            // itself is an image its dimensions ride here as `media.url`.
            'media' => $link?->media(),
        ];
    }
}
