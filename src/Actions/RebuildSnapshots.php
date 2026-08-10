<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Activity;

/**
 * Full snapshot pass: iterates every DISTINCT entity referenced by any
 * activity role, refreshes its snapshot, and backfills the cached FK
 * columns. Heavy by design — the deploy/backfill tool, not the scheduled
 * maintenance worker (that's TrickleSnapshots).
 */
class RebuildSnapshots
{
    public const ROLES = ['actor', 'object', 'target', 'context'];

    /**
     * @return array{snapshotted: int, missing: int}
     */
    public function __invoke(): array
    {
        $snapshotted = 0;
        $missing = 0;

        foreach (self::ROLES as $role) {
            $pairs = $this->activityQuery()
                ->whereNotNull("{$role}_type")
                ->distinct()
                ->get(["{$role}_type as type", "{$role}_id as id"]);

            foreach ($pairs as $pair) {
                $model = $this->resolve($pair->type, $pair->id);

                if ($model === null) {
                    $missing++;

                    continue;
                }

                $snapshot = (new SnapshotEntity)($model);

                $this->activityQuery()
                    ->where("{$role}_type", $pair->type)
                    ->where("{$role}_id", $pair->id)
                    ->update(["cached_{$role}_id" => $snapshot->getKey()]);

                $snapshotted++;
            }
        }

        return ['snapshotted' => $snapshotted, 'missing' => $missing];
    }

    /**
     * @return (Model&Feedable)|null
     */
    protected function resolve(string $type, int|string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        if ($class === null || ! is_a($class, Model::class, true) || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        /** @var (Model&Feedable)|null guarded by the is_a checks above */
        return $class::query()->find($id);
    }

    protected function activityQuery()
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
