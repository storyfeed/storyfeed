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
 * newest-first, REPORTS orphans — and converges SHAPE-STALE snapshots
 * (rows whose fingerprint no longer matches what today's toFeed()
 * produces; see Support\ShapeSignature). Deploy a changed toFeed()/DTO
 * and the feed heals itself on the existing schedule, no command needed.
 *
 * Schedule: $schedule->command('storyfeed:trickle')->everyFifteenMinutes()
 *           ->withoutOverlapping();
 *
 * ## Pruning is OPT-IN (2026-08-26), and it did not used to be
 *
 * An activity whose role cannot be resolved used to be DELETED by this worker,
 * on every run, by default. The documentation recommended scheduling it every
 * fifteen minutes, so the destructive behaviour was the one an installer got by
 * following the instructions and reading no further.
 *
 * A consumer found what that costs. In their portal EVERY activity the operator
 * had performed carried an unresolvable actor — their `User` model was not
 * `Feedable` — so an entire class of "things the operator did" was queued for
 * removal by a worker whose documented purpose is snapshot convergence. Their
 * standing rule is "no pruning; this is an operations vault and I need it for
 * audit". The rows were soft-deleted, so recoverable — but they leave the feed
 * silently, and retention pruning force-deletes them later.
 *
 * An unresolvable role is nearly always a MISSING `Feedable`, which is a bug in
 * the app, and deleting the evidence of a bug is a poor way to report it. So
 * the default is now to count them: `storyfeed:doctor` and this worker's own
 * output name the number, and `storyfeed.trickle.prune` (or `--prune`) turns
 * deletion back on for an app that genuinely wants it.
 *
 * ## What the report costs, and why the scan grows to cover it
 *
 * An orphan can never gain a cached id, so it matches `uncached()` forever: a
 * fixed population of them would fill a `limit`-sized page every run and starve
 * every newer row behind it. That starvation is why deletion was here in the
 * first place, and it is a real failure, not a theoretical one.
 *
 * So a run that skips orphans KEEPS FETCHING — excluding what it has already
 * examined — until it has done `limit` real snapshots or hit a bounded ceiling.
 * The budget is spent on work, not on rediscovering the same broken rows, and
 * the ceiling stops a table that is entirely orphans from turning one run into
 * a full scan.
 */
class TrickleSnapshots
{
    /**
     * How far past `limit` a run may look while stepping over orphans, so a
     * standing population of them cannot starve the rows behind them.
     */
    protected const SCAN_CEILING = 5;

    /**
     * @param  bool|null  $prune  null defers to `storyfeed.trickle.prune`
     * @return array{snapshotted: int, pruned: int, unresolved: int, reshaped: int}
     */
    public function __invoke(?int $limit = null, ?bool $prune = null): array
    {
        $limit ??= (int) config('storyfeed.trickle.limit', 200);
        $prune ??= (bool) config('storyfeed.trickle.prune', false);

        $snapshotted = 0;
        $pruned = 0;
        $unresolved = 0;

        /** @var array<int, mixed> $examined */
        $examined = [];
        $ceiling = $limit * self::SCAN_CEILING;

        while ($snapshotted + $pruned < $limit && count($examined) < $ceiling) {
            $activities = $this->activityQuery()
                ->uncached()
                // Rows this RUN has already stepped over. Without it the same
                // orphans come back on every fetch and the loop never advances.
                ->when($examined !== [], fn ($query) => $query->whereNotIn('id', $examined))
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get();

            if ($activities->isEmpty()) {
                break;
            }

            foreach ($activities as $activity) {
                $examined[] = $activity->getKey();

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
                    if ($prune) {
                        $activity->delete();
                        SyncParticipants::forget($activity->getKey());
                        $pruned++;
                    } else {
                        // Counted, not destroyed. An unresolvable role is nearly
                        // always a missing Feedable, and deleting the evidence of
                        // a bug is a poor way to report it.
                        $unresolved++;
                    }

                    continue;
                }

                $activity->save();

                (new WriteGroupings)($activity); // converge legacy rows into groups

                $snapshotted++;

                if ($snapshotted + $pruned >= $limit) {
                    break;
                }
            }
        }

        $reshaped = $this->convergeShapes($limit - $snapshotted);

        return [
            'snapshotted' => $snapshotted,
            'pruned' => $pruned,
            'unresolved' => $unresolved,
            'reshaped' => $reshaped,
        ];
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
