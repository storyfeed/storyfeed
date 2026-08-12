<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\ShapeSignature;

/**
 * The self-correcting scheduled worker: snapshots uncached activities
 * newest-first, prunes orphans — and converges SHAPE-STALE snapshots
 * (rows whose fingerprint no longer matches what today's toFeed()
 * produces; see Support\ShapeSignature). Deploy a changed toFeed()/DTO
 * and the feed heals itself on the existing schedule, no command needed.
 *
 * Schedule: $schedule->command('storyfeed:trickle')->everyFifteenMinutes()
 *           ->withoutOverlapping();
 */
class TrickleSnapshots
{
    /**
     * @return array{snapshotted: int, pruned: int, reshaped: int}
     */
    public function __invoke(?int $limit = null): array
    {
        $limit ??= (int) config('storyfeed.trickle.limit', 200);

        $snapshotted = 0;
        $pruned = 0;

        $activities = $this->activityQuery()
            ->uncached()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        foreach ($activities as $activity) {
            $orphaned = false;

            foreach (RebuildSnapshots::ROLES as $role) {
                if ($activity->{"{$role}_type"} === null || $activity->{"cached_{$role}_id"} !== null) {
                    continue;
                }

                $model = $this->resolve($activity->{"{$role}_type"}, $activity->{"{$role}_id"});

                if ($model === null) {
                    $orphaned = true;

                    continue;
                }

                $activity->{"cached_{$role}_id"} = (new SnapshotEntity)($model)->getKey();
            }

            if ($orphaned) {
                $activity->delete();
                $pruned++;

                continue;
            }

            $activity->save();

            (new WriteGroupings)($activity); // converge legacy rows into groups

            $snapshotted++;
        }

        $reshaped = $this->convergeShapes($limit - $snapshotted);

        return ['snapshotted' => $snapshotted, 'pruned' => $pruned, 'reshaped' => $reshaped];
    }

    /**
     * The shape phase, within the run's remaining budget: per snapshotted
     * model type, compute the CURRENT signature from one live model, then
     * re-snapshot rows whose stored fingerprint differs (null = pre-shape
     * rows, also stale). Models that no longer exist are skipped — the
     * activity-orphan path prunes their stories.
     */
    protected function convergeShapes(int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);

        $reshaped = 0;

        $types = $snapshot::query()->distinct()->pluck('model_type');

        foreach ($types as $type) {
            if ($budget <= 0) {
                break;
            }

            // One live model tells us what today's code produces.
            $sample = null;

            foreach ($snapshot::query()->where('model_type', $type)->latest('updated_at')->limit(5)->get() as $row) {
                if ($sample = $this->resolve($row->model_type, $row->model_id)) {
                    break;
                }
            }

            if ($sample === null) {
                continue;
            }

            $current = ShapeSignature::for($sample->toFeed(), $sample::class);

            $stale = $snapshot::query()
                ->where('model_type', $type)
                ->where(fn ($q) => $q->whereNull('shape')->orWhere('shape', '!=', $current))
                ->limit($budget)
                ->get();

            foreach ($stale as $row) {
                $model = $this->resolve($row->model_type, $row->model_id);

                if ($model !== null) {
                    (new SnapshotEntity)($model);
                    $reshaped++;
                }

                $budget--;
            }
        }

        return $reshaped;
    }

    /**
     * @return (Model&Feedable)|null
     */
    protected function resolve(string $type, int|string $id): ?Model
    {
        return MorphResolver::feedable($type, $id);
    }

    protected function activityQuery(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
