<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Support\Collection;
use Storyfeed\FeedContext;
use Storyfeed\FeedHeadline;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\LinkResolver;
use Storyfeed\Support\ModelHydrator;
use Storyfeed\Support\TombstoneRules;

final class ReadPathPresenterOracle extends NodePresenter
{
    public function forPage(Collection $slices): static
    {
        $hydrator = new ModelHydrator;
        $tombstoneIds = [];

        foreach ($slices as $slice) {
            foreach ($slice->members as $activity) {
                foreach (ActivityRoles::PAYLOAD as $role) {
                    $hydrator->seed($activity->{"{$role}_type"}, $activity->{"{$role}_id"});

                    if ($activity->{"{$role}_type"} === FeedTombstone::MORPH_ALIAS && $activity->{"{$role}_id"} !== null) {
                        $tombstoneIds[(string) $activity->{"{$role}_id"}] = true;
                    }
                }
            }
        }

        $presenter = clone $this;
        $presenter->hydrator = $hydrator;
        $presenter->links = new LinkResolver;
        $presenter->tombstones = self::loadTombstones(array_keys($tombstoneIds));

        return $presenter;
    }

    protected function samples(Collection $members, array $distinct, array $tombstoned): array
    {
        $sample = [];
        $counts = [];
        $distinctTombstoned = [];

        foreach (self::GROUP_ROLES as $role => [$key, $relation]) {
            $unique = $members
                ->filter(fn (Activity $a) => $a->{"{$role}_type"} !== null)
                ->unique(fn (Activity $a) => $a->{"{$role}_type"}.':'.$a->{"{$role}_id"})
                ->values();

            $limit = config("storyfeed.grouping.sample_limits.{$role}", 3);

            // Live entities first, tombstones after, each in member order:
            // "Dana, Sam and a former customer" over "a former customer, a
            // former customer and Dana". Curation, not contract.
            $sample[$key] = $unique
                ->sortBy(fn (Activity $a) => $a->{"{$role}_type"} === FeedTombstone::MORPH_ALIAS ? 1 : 0)
                ->take(is_int($limit) && $limit > 0 ? $limit : 3)
                ->map(fn (Activity $a) => $this->entity($a->{"{$role}_type"}, $a->{"{$role}_id"}, $a->{$relation}))
                ->values()
                ->all();

            // True totals from the aggregate query; the in-page unique count
            // is the floor when a caller built the slice without them.
            $counts[$key] = max($distinct[$role] ?? 0, $unique->count());
            $distinctTombstoned[$key] = max(
                $tombstoned[$role] ?? 0,
                $unique->filter(fn (Activity $a) => $a->{"{$role}_type"} === FeedTombstone::MORPH_ALIAS)->count(),
            );
        }

        return [$sample, $counts, $distinctTombstoned];
    }

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
            'published_at' => $activity->published_at?->toISOString(),
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
            'actor' => $this->entity($activity->actor_type, $activity->actor_id, $activity->cachedActor),
            'object' => $this->entity($activity->object_type, $activity->object_id, $activity->cachedObject),
            'target' => $this->entity($activity->target_type, $activity->target_id, $activity->cachedTarget),
            'context' => $this->entity($activity->context_type, $activity->context_id, $activity->cachedContext),
            'origin' => $this->entity($activity->origin_type, $activity->origin_id, $activity->cachedOrigin),
            'result' => $this->entity($activity->result_type, $activity->result_id, $activity->cachedResult),
            'instrument' => $this->entity($activity->instrument_type, $activity->instrument_id, $activity->cachedInstrument),
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

    public function groupNode(GroupSlice $slice): array
    {
        $members = $slice->members;
        $first = $members->first();

        [$sample, $distinct, $distinctTombstoned] = $this->samples($members, $slice->distinct, $slice->tombstoned);

        [$template, $headline] = $this->aggregateHeadline($slice, $distinct);

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

        // A digest row that spans verbs names none, and wears no glyph of
        // its own: the rail shows the person, and each phrase its verb.
        $verb = $first->verb;

        $node = [
            'kind' => 'group',
            // Namespaced and versioned: the digest must not collide across
            // axes once a group can win on more than `repeat`. A digest row
            // hashes its bucket (`summary.week`), so periods never collide.
            'id' => 'grp_'.sha1("v1\x1f{$slice->axis}\x1f{$slice->hash}"),
            'axis' => $slice->axis,
            'count' => $slice->count,
            'verb' => $verb,
            'published_at' => $first->published_at?->toISOString(),
            'headline_template' => $template,
            'headline' => $headline,
            'glyph' => $verb === null ? null : $this->storyfeed->icon($this->objectType($first), $verb),
            'glyph_intent' => $verb === null ? null : $this->storyfeed->glyphIntent($this->objectType($first), $verb),
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

    protected function entity(?string $type, int|string|null $id, ?Snapshot $snapshot): ?array
    {
        if ($type === null) {
            return null;
        }

        $data = $snapshot->data ?? [];

        // No snapshot ⇒ no link regeneration: the contract promises degraded
        // entities arrive with url: null, and calling the app's resolver
        // with empty data makes every naive implementation warn.
        $links = $this->links ?? new LinkResolver;
        $link = $snapshot === null ? null : $links->resolve(new FeedContext(
            type: $type,
            key: $id,
            label: $snapshot->label,
            data: $data,
            feed: $this->feed,
            hydrator: $this->hydrator ?? new ModelHydrator,
            routeKey: $snapshot->meta['route_key'] ?? null,
        ));

        return [
            'type' => $type,
            'id' => $id === null ? null : (string) $id,
            'label' => $link->label ?? $snapshot?->label,
            'link' => $link?->link === null ? null : ['href' => $link->link->href, 'modal' => $link->link->modal, 'attributes' => $link->link->attributes],
            'data' => $data,
            // Additive (2026-09-05): the typed image slots, or null.
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
            'body' => self::bodyOrNull([...($snapshot->body ?? []), ...$links->body($link, $type)]),
            // Additive (2026-09-23): what a deleted entity left behind, or
            // null. Distinct from DEGRADED (a live entity with no snapshot
            // yet: `label: null`, `tombstone: null`) and from ANONYMOUS (no
            // entity at all: the role is null). See docs/payload.md.
            'tombstone' => $type === FeedTombstone::MORPH_ALIAS && $id !== null
                ? $this->tombstone($id)?->toPayload()
                : null,
            ...array_filter([
                'content' => $snapshot?->content,
                'mediaType' => $snapshot?->media_type,
                'attributedTo' => $snapshot?->attributed_to,
            ], fn ($value) => $value !== null),
        ];
    }

    private static function bodyOrNull(array $forms): ?array
    {
        return $forms === [] ? null : $forms;
    }

    protected function tombstoneFact(Activity $activity): array
    {
        $tombstoned = array_values(array_filter(
            ActivityRoles::PAYLOAD,
            fn (string $role): bool => $activity->{"{$role}_type"} === FeedTombstone::MORPH_ALIAS,
        ));

        if ($tombstoned === []) {
            return [[], false];
        }

        $constitutive = app(TombstoneRules::class)->constitutiveRoles($this->objectType($activity), (string) $activity->verb);

        return [$tombstoned, array_intersect($tombstoned, $constitutive) !== []];
    }
}
