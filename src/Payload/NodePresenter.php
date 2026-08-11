<?php

namespace Storyfeed\Payload;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedLink;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Throwable;

/**
 * Builds Payload v1 nodes (docs/payload.md) from hydrated activities.
 *
 * Entities are self-describing: label/component/data come from the snapshot,
 * while the URL is regenerated live via the model's static toFeedLink() —
 * wrapped so one broken link never breaks the feed. Missing snapshots
 * degrade to placeholder entities; activities are never withheld.
 */
class NodePresenter
{
    public function __construct(
        protected StoryfeedManager $storyfeed,
    ) {}

    /**
     * @param  Collection<int, Activity>  $members  same-group activities, newest first
     */
    public function node(Collection $members): array
    {
        return $members->count() === 1
            ? $this->activityNode($members->first())
            : $this->groupNode($members);
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
     * @param  Collection<int, Activity>  $members
     */
    public function groupNode(Collection $members): array
    {
        $first = $members->first();

        $actorEntities = $members
            ->filter(fn (Activity $a) => $a->actor_type !== null)
            ->unique(fn (Activity $a) => $a->actor_type.':'.$a->actor_id)
            ->values();

        $exemplars = $actorEntities
            ->take(3)
            ->map(fn (Activity $a) => $this->entity($a->actor_type, $a->actor_id, $a->cachedActor))
            ->all();

        [$template, $headline] = $this->headline($first);

        return [
            'kind' => 'group',
            'id' => 'grp_'.sha1((string) $first->group_hash),
            'axis' => 'repeat',
            'count' => $members->count(),
            'verb' => $first->verb,
            'published_at' => $first->published_at?->toISOString(),
            'headline_template' => $template,
            'headline' => $headline,
            'icon' => $this->storyfeed->icon($first->object_type, $first->verb),
            'exemplars' => [
                'actors' => $exemplars,
                'target' => $this->entity($first->target_type, $first->target_id, $first->cachedTarget),
                'context' => $this->entity($first->context_type, $first->context_id, $first->cachedContext),
            ],
            'others_count' => max(0, $actorEntities->count() - count($exemplars)),
            'children' => $members->map(fn (Activity $a) => $this->activityNode($a))->values()->all(),
            'children_truncated' => false,
        ];
    }

    protected function entity(?string $type, int|string|null $id, ?Snapshot $snapshot): ?array
    {
        if ($type === null) {
            return null;
        }

        $data = $snapshot->data ?? [];

        $link = $this->resolveLink($type, $data);

        return [
            'type' => $type,
            'id' => $id === null ? null : (string) $id,
            'label' => $link->label ?? $snapshot?->label,
            'url' => $link?->url,
            'attributes' => $link->attributes ?? [],
            'modal' => $link->modal ?? false,
            'component' => $snapshot?->component,
            'data' => $data,
        ];
    }

    protected function resolveLink(string $type, array $data): ?FeedLink
    {
        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        if ($class === null || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        try {
            return $class::toFeedLink($data);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
