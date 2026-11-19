<?php

namespace Storyfeed\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Storyfeed\ActivityContext;
use Storyfeed\FeedContext;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use WeakMap;

/** @internal Builds headline contexts from the read path's loaded snapshots. */
final class ActivityContextFactory
{
    /**
     * @param  string|null  $type  the object type the headline was resolved for (a tombstoned object's former type)
     * @param  WeakMap<Snapshot, array<string, FeedContext>>|null  $contexts  a page's role contexts by snapshot, so
     *                                                                        members sharing an entity decode it once (#258)
     */
    public static function make(Activity $activity, ?string $feed = null, ?ModelHydrator $hydrator = null, ?string $type = null, ?WeakMap $contexts = null): ActivityContext
    {
        $hydrator ??= new ModelHydrator;
        $roles = [];

        foreach (ActivityRoles::PAYLOAD as $role) {
            $roleType = $activity->{"{$role}_type"};
            /** @var Snapshot|null $snapshot */
            $snapshot = $roleType === null ? null : $activity->{'cached'.ucfirst($role)};
            if ($roleType === null) {
                $roles[$role] = null;

                continue;
            }

            $key = $activity->{"{$role}_id"};
            $make = fn (): FeedContext => new FeedContext(
                type: $roleType,
                key: $key,
                label: $snapshot?->label,
                data: $snapshot->data ?? [],
                feed: $feed,
                hydrator: $hydrator,
                routeKey: $snapshot->meta['route_key'] ?? null,
                model: ModelHydrator::carried($snapshot),
            );

            if ($contexts === null || $snapshot === null) {
                $roles[$role] = $make();

                continue;
            }

            $contexts[$snapshot] ??= [];
            $roles[$role] = $contexts[$snapshot][$roleType.'|'.$key] ??= $make();
        }

        return new ActivityContext(
            ...$roles,
            data: $activity->data ?? [],
            verb: $activity->verb,
            publishedAt: self::immutable($activity->published_at),
            casts: app(StoryfeedManager::class)->dataCasts($type ?? $activity->object_type, $activity->verb),
        );
    }

    /**
     * CarbonImmutable::instance() without its `U.u` round trip through a
     * string, which was a fifth of a headline closure's cost (#258). The
     * settings carry over as instance() carries them.
     */
    private static function immutable(?CarbonInterface $date): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        $immutable = CarbonImmutable::createFromInterface($date);
        $settings = $date->getSettings();
        if (! $date->hasLocalTranslator()) {
            unset($settings['locale']);
        }

        return $settings === [] ? $immutable : $immutable->settings($settings);
    }
}
