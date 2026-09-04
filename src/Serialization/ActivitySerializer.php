<?php

namespace Storyfeed\Serialization;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\Context;
use Storyfeed\FeedContext;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\LinkResolver;

/**
 * Serializes an Activity as an AS2.0 JSON-LD document
 * (docs/activity-streams.md). Storage stays Laravel-native; conformance
 * lives entirely here, at the boundary.
 *
 * Rules that keep the documents spec-valid:
 *  - The AS2 `type` is DERIVED from the verb registry, never stored; the
 *    app verb always rides along as `sf:verb`, so Storyfeed→Storyfeed
 *    round-trips are lossless and foreign consumers degrade to the mapped
 *    (or base) type.
 *  - A verb mapped to an intransitive type (Arrive/Travel/Question) on an
 *    activity that carries an object emits base `Activity` and KEEPS the
 *    object — degrade, never drop.
 *  - Entities embed from snapshots; entities without snapshots serialize
 *    as bare references. Presentation extras (icon, component, templates)
 *    never appear — they are meaningless to a federation peer.
 */
class ActivitySerializer
{
    /**
     * Was a hand-copied duplicate of Context::DEFAULT — which nothing used,
     * so the two could have drifted silently. One source now.
     */
    public const CONTEXT = Context::DEFAULT;

    public function __construct(
        protected StoryfeedManager $storyfeed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function activity(Activity $activity, bool $root = true): array
    {
        $document = $root ? ['@context' => self::CONTEXT] : [];

        return [
            ...$document,
            'id' => $this->iri($activity),
            'type' => $this->type($activity),
            'sf:verb' => $activity->verb,
            ...array_filter([
                'actor' => $this->entity($activity->actor_type, $activity->cachedActor, actor: true),
                'object' => $this->collectionObject($activity)
                    ?? $this->entity($activity->object_type, $activity->cachedObject),
                'target' => $this->entity($activity->target_type, $activity->cachedTarget),
                'context' => $this->entity($activity->context_type, $activity->cachedContext),
            ], fn (?array $entity) => $entity !== null),
            'published' => $activity->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function iri(Activity $activity): string
    {
        $prefix = trim((string) config('storyfeed.routes.prefix', 'storyfeed'), '/');

        return url("{$prefix}/activities/{$activity->uid}");
    }

    /**
     * The AS2 type for the activity. Unmapped verbs emit the base
     * `Activity` type (spec-legal); so does an intransitive mapping that
     * conflicts with a present object.
     */
    protected function type(Activity $activity): string
    {
        $type = $this->storyfeed->activityType($activity->verb);

        if ($type instanceof ActivityType && $type->isIntransitive() && $activity->object_type !== null) {
            return 'Activity';
        }

        return $this->storyfeed->activityTypeValue($activity->verb);
    }

    /**
     * A composite parent's object is an OrderedCollection — the one
     * aggregate AS2 natively supports, and the reason composites exist at
     * the serialization boundary: "uploaded 6 files" is one Activity whose
     * object is a collection of six, each member entity embedded live from
     * its snapshot, reverse-chronological.
     *
     * @return array<string, mixed>|null null for non-composite activities
     */
    protected function collectionObject(Activity $activity): ?array
    {
        if ($activity->object_type !== null) {
            return null;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $memberIds = $grouping::query()
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->where('winner', true)
            ->pluck('activity_id');

        if ($memberIds->isEmpty()) {
            return null;
        }

        $model = config('storyfeed.models.activity', Activity::class);

        $members = $model::query()
            ->whereKey($memberIds)
            ->with('cachedObject')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        return [
            'type' => 'OrderedCollection',
            'totalItems' => $members->count(),
            'orderedItems' => $members
                ->map(fn (Activity $member) => $this->entity($member->object_type, $member->cachedObject))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * An embedded entity object, or a bare reference when un-snapshotted.
     * Null when the role is empty.
     *
     * @return array<string, mixed>|null
     */
    protected function entity(?string $alias, ?Snapshot $snapshot, bool $actor = false): ?array
    {
        if ($alias === null) {
            return null;
        }

        $data = $snapshot->data ?? [];

        // A Party row carries its own AS2 type (Service for integrations,
        // Application for the app itself); it wins over the class default.
        $type = $this->entityType($alias, $data);

        // No snapshot ⇒ bare reference, no link regeneration (same rule as
        // the payload presenter: a resolver is never called with empty data).
        //
        // No feed, on purpose. A federation document describes an activity,
        // not a surface, and must read the same whoever fetched it — so the
        // resolver is told there is no feed and answers from its default arm.
        $url = $snapshot === null ? null : LinkResolver::resolve(new FeedContext(
            type: $alias,
            id: $snapshot->model_id,
            label: $snapshot->label,
            data: $data,
            feed: null,
        ))?->url;
        $absolute = $url === null ? null : url($url);

        return array_filter([
            'type' => $type,
            // The entity's IRI, when the host app can mint one. Emitted as
            // `id` for actors (AS2 actors want identity) and `url` for the
            // other roles, matching the document-shape examples.
            'id' => $actor ? $absolute : null,
            'name' => $snapshot?->label,
            'url' => $actor ? null : $absolute,
        ], fn ($value) => $value !== null);
    }

    /**
     * Only a Party's snapshot carries a per-row AS2 type — a domain model's
     * snapshot may have its own `type` field meaning something else
     * entirely, so the override is scoped to the party alias.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function entityType(string $alias, array $data): string
    {
        if ($alias === config('storyfeed.morph_alias', 'storyfeed.party')) {
            $own = $data['type'] ?? null;

            if (is_string($own) && $own !== '') {
                return $own;
            }
        }

        return $this->storyfeed->objectTypeValue($alias);
    }
}
