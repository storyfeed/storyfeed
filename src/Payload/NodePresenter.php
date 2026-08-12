<?php

namespace Storyfeed\Payload;

use Closure;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\LinkResolver;
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
     * :others tokens. Without an entry the group falls back to the singular
     * grammar of its head member — the honest degradation, and what makes an
     * actors-axis group read "Sally uploaded a file" until it is authored.
     * `storyfeed:doctor` and GrammarCoverage surface exactly that gap.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function aggregateHeadline(GroupSlice $slice): array
    {
        $entry = $this->storyfeed->aggregateTemplate($slice->axis, (string) $slice->members->first()?->verb);

        if ($entry === null) {
            return $this->headline($slice->members->first());
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

    public function groupNode(GroupSlice $slice): array
    {
        $members = $slice->members;
        $first = $members->first();

        $actorEntities = $members
            ->filter(fn (Activity $a) => $a->actor_type !== null)
            ->unique(fn (Activity $a) => $a->actor_type.':'.$a->actor_id)
            ->values();

        $exemplars = $actorEntities
            ->take(3)
            ->map(fn (Activity $a) => $this->entity($a->actor_type, $a->actor_id, $a->cachedActor))
            ->all();

        [$template, $headline] = $this->aggregateHeadline($slice);

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
            'exemplars' => [
                'actors' => $exemplars,
                'target' => $this->entity($first->target_type, $first->target_id, $first->cachedTarget),
                'context' => $this->entity($first->context_type, $first->context_id, $first->cachedContext),
            ],
            'others_count' => max(0, ($slice->actorCount ?? $actorEntities->count()) - count($exemplars)),
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
        // entities arrive with url: null, and calling the app's toFeedLink()
        // with an empty array makes every naive implementation warn.
        $link = $snapshot === null ? null : LinkResolver::resolve($type, $data);

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
}
