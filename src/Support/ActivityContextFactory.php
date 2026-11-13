<?php

namespace Storyfeed\Support;

use Carbon\CarbonImmutable;
use Storyfeed\ActivityContext;
use Storyfeed\FeedContext;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;

/** @internal Builds headline contexts from the read path's loaded snapshots. */
final class ActivityContextFactory
{
    /** @param  string|null  $type  the object type the headline was resolved for (a tombstoned object's former type) */
    public static function make(Activity $activity, ?string $feed = null, ?ModelHydrator $hydrator = null, ?string $type = null): ActivityContext
    {
        $hydrator ??= new ModelHydrator;
        $roles = [];

        foreach (ActivityRoles::PAYLOAD as $role) {
            $roleType = $activity->{"{$role}_type"};
            /** @var Snapshot|null $snapshot */
            $snapshot = $roleType === null ? null : $activity->{'cached'.ucfirst($role)};
            $roles[$role] = $roleType === null ? null : new FeedContext(
                type: $roleType,
                key: $activity->{"{$role}_id"},
                label: $snapshot?->label,
                data: $snapshot->data ?? [],
                feed: $feed,
                hydrator: $hydrator,
                routeKey: $snapshot->meta['route_key'] ?? null,
                model: ModelHydrator::carried($snapshot),
            );
        }

        return new ActivityContext(
            ...$roles,
            data: $activity->data ?? [],
            verb: $activity->verb,
            publishedAt: $activity->published_at === null ? null : CarbonImmutable::instance($activity->published_at),
            casts: app(StoryfeedManager::class)->dataCasts($type ?? $activity->object_type, $activity->verb),
        );
    }
}
