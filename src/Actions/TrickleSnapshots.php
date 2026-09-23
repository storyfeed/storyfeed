<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Meta;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\ShapeSignature;

/**
 * The self-correcting scheduled worker: snapshots uncached activities
 * newest-first, REPORTS orphans — and converges SHAPE-STALE snapshots
 * (rows whose fingerprint no longer matches what today's toFeed()
 * produces; see Support\ShapeSignature). Deploy a changed toFeed()/DTO
 * and the feed heals itself on the existing schedule, no command needed.
 *
 * It is also the guarantee behind tombstones. A model deleted without its
 * `deleted` event (a bulk delete, raw SQL, a database cascade) gets its
 * tombstone here, marked approximate, and a tombstoned model that is back
 * (a bulk restore) is restored. A role whose model is simply gone is no
 * longer an orphan; orphans are roles whose class doesn't resolve.
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

    /** Where the deletion sweep resumes, in `feed_meta`. */
    protected const DELETIONS_CURSOR = 'trickle.deletions_cursor';

    /** Where the restore sweep resumes, in `feed_meta`. */
    protected const RESTORES_CURSOR = 'trickle.restores_cursor';

    /**
     * @param  bool|null  $prune  null defers to `storyfeed.trickle.prune`
     * @return array{snapshotted: int, pruned: int, unresolved: int, reshaped: int, tombstoned: int, restored: int}
     */
    public function __invoke(?int $limit = null, ?bool $prune = null): array
    {
        $limit ??= (int) config('storyfeed.trickle.limit', 200);
        $prune ??= (bool) config('storyfeed.trickle.prune', false);

        $snapshotted = 0;
        $pruned = 0;
        $unresolved = 0;
        $tombstoned = 0;

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
                $repointed = false;

                foreach (RebuildSnapshots::ROLES as $role) {
                    if ($activity->{"{$role}_type"} === null || $activity->{"cached_{$role}_id"} !== null) {
                        continue;
                    }

                    $model = $this->resolve($activity->{"{$role}_type"}, $activity->{"{$role}_id"});

                    if ($model === null && ($found = $this->tombstone($activity->{"{$role}_type"}, [$activity->{"{$role}_id"}])) > 0) {
                        // Deleted without an event. The tombstone took this
                        // role, and every other row naming the same entity.
                        $tombstoned += $found;
                        $repointed = true;

                        continue;
                    }

                    if ($model === null) {
                        $orphaned = true;

                        continue;
                    }

                    $activity->{"cached_{$role}_id"} = (new SnapshotEntity)($model)->getKey();
                }

                if ($repointed) {
                    // The repoint wrote the row behind this model's back;
                    // read it again, keeping the snapshots cached above.
                    $dirty = $activity->getDirty();
                    $activity->refresh()->forceFill($dirty);
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

        $restored = 0;

        if (TombstoneEntity::installed()) {
            $tombstoned += $this->discoverDeletions($limit);
            $restored = $this->restoreReturned($limit);
        }

        $result = [
            'snapshotted' => $snapshotted,
            'pruned' => $pruned,
            'unresolved' => $unresolved,
            'reshaped' => $reshaped,
            'tombstoned' => $tombstoned,
            'restored' => $restored,
        ];

        MaintenanceHistory::record('trickle', $result);

        return $result;
    }

    /**
     * The shape phase, within the run's remaining budget: per snapshotted
     * model type, a live sample says what today's code produces, rows whose
     * stored fingerprint differs from it are CANDIDATES, and each candidate is
     * then checked against its own model before anything is written. Models
     * that no longer exist are skipped; their snapshots are retained. The
     * activity-orphan path only checks uncached roles and soft-deletes
     * activities only when pruning is enabled.
     *
     * THE SAMPLE IS A FILTER, NOT THE ANSWER, and it took a consumer's
     * production to show why. Shape is not a property of a class: it is a
     * property of a ROW. `ShapeSignature` tags every scalar with its type, so
     * a nullable key — a `status` set on most rows and null on a few — yields
     * two legitimate fingerprints for one class, with nothing deployed and
     * nothing stale.
     *
     * Comparing every row against one sample then rewrote whichever cohort the
     * sample did not belong to, forever. A reshape touches `updated_at`, which
     * changes which row is sampled next, so the two cohorts took turns and the
     * reported count climbed instead of falling: 14 reshaped, then 32, twenty
     * seconds apart, on a feed where nothing had changed.
     *
     * A row checked against ITSELF converges after one pass, because a
     * re-snapshotted row agrees with its own model by construction. That is
     * the whole fix, and it costs one resolve per candidate.
     *
     * NEWEST FIRST, and there is never a good reason for the other way. Picture
     * a feed with ten years of stories in it: oldest-first spends weeks
     * repairing 2016 while today stays wrong. The rows a reader loads are the
     * top of the feed and the pages just behind it, so the healing should walk
     * in the same direction they read. `php artisan optimize` compiles the
     * newest window at deploy; this continues from where that stopped, in the
     * same direction.
     *
     * `updated_at` is the axis because it is the one a snapshot carries, and it
     * is a fair proxy: publishing re-snapshots the entity it touches, so an
     * entity named by a recent activity has a recent `updated_at`. It is a
     * proxy and not the feed's own order, which would need a join per row.
     *
     * AND ORDERING IS NOT WHAT STOPS STARVATION -- an earlier version of this
     * comment claimed it was, and was wrong. A candidate that agrees with
     * itself is skipped without a write, so its `updated_at` does not move and
     * it is selected again on the next run whichever way the walk runs. The
     * budget can be spent entirely on rows nobody will ever rewrite.
     *
     * That hole is real, predates this ordering, and is not closed by choosing
     * a direction. It needs a mark saying a row has been verified -- the
     * deploy frontier is where that belongs, and until it exists the guard is
     * the budget itself.
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

            $current = ShapeSignature::for(app(Feedables::class)->toFeed($sample), $sample::class);

            $candidates = $snapshot::query()
                ->where('model_type', $type)
                // A row with no `meta` predates the recorded route key, and
                // is refreshed on the same budget as a stale shape.
                ->where(fn ($q) => $q->whereNull('shape')->orWhere('shape', '!=', $current)->orWhereNull('meta'))
                ->latest('updated_at')
                ->limit($budget)
                ->get();

            foreach ($candidates as $row) {
                $budget--;

                $model = $this->resolve($row->model_type, $row->model_id);

                if ($model === null) {
                    continue;
                }

                // The row against its own model. A stored fingerprint that
                // still matches what this row produces today is not stale —
                // it is a second legitimate shape of the same class, and
                // rewriting it would change nothing and undo nothing.
                if ($row->shape !== null && $row->meta !== null
                    && $row->shape === ShapeSignature::for(app(Feedables::class)->toFeed($model), $model::class)) {
                    continue;
                }

                (new SnapshotEntity)($model);
                $reshaped++;
            }
        }

        return $reshaped;
    }

    /**
     * The deletion phase: a sweep through the snapshots, `$budget` rows per
     * run, for models that are gone without their `deleted` event having
     * been heard (a bulk `delete()`, raw SQL, a database cascade). Each one
     * gets its tombstone, marked approximate, since the time it was found is
     * all there is. The event is the instant path; this is the guarantee.
     *
     * A snapshotted role is never uncached, so the activity loop above can't
     * see these: the snapshot is the thing to check. The sweep resumes from a
     * cursor in `feed_meta`, and starts over once it reaches the end.
     */
    protected function discoverDeletions(int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);
        $cursor = (int) $this->meta()::query()->where('key', self::DELETIONS_CURSOR)->value('value');

        $rows = $snapshot::query()
            ->where('id', '>', $cursor)
            ->where('model_type', '!=', FeedTombstone::MORPH_ALIAS)
            ->orderBy('id')
            ->limit($budget)
            ->get(['id', 'model_type', 'model_id']);

        $this->meta()::query()->updateOrCreate(
            ['key' => self::DELETIONS_CURSOR],
            ['value' => (string) ($rows->count() < $budget ? 0 : $rows->last()?->getKey())],
        );

        $found = 0;

        foreach ($rows->groupBy('model_type') as $type => $group) {
            $found += $this->tombstone((string) $type, $group->pluck('model_id')->all());
        }

        return $found;
    }

    /**
     * The restore phase: restorable tombstones whose model exists again (a
     * bulk `restore()`, which fires no events) are repointed back. Same
     * budget and the same kind of cursor as the deletion phase.
     */
    protected function restoreReturned(int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $tombstones = config('storyfeed.models.tombstone', FeedTombstone::class);
        $cursor = (int) $this->meta()::query()->where('key', self::RESTORES_CURSOR)->value('value');

        $rows = $tombstones::query()
            ->where('id', '>', $cursor)
            ->where('restorable', true)
            ->orderBy('id')
            ->limit($budget)
            ->get();

        $this->meta()::query()->updateOrCreate(
            ['key' => self::RESTORES_CURSOR],
            ['value' => (string) ($rows->count() < $budget ? 0 : $rows->last()?->getKey())],
        );

        $restored = 0;

        foreach ($rows->groupBy('model_type') as $type => $group) {
            $class = MorphResolver::classFor((string) $type);

            if ($class === null || ! is_a($class, Model::class, true) || ! app(Feedables::class)->isFeedable($class)) {
                continue;
            }

            $models = $class::query()->withoutGlobalScopes()
                ->whereIn((new $class)->getQualifiedKeyName(), $group->pluck('model_id')->all())
                ->get()
                ->filter(fn (Model $model) => TombstoneEntity::trashedAt($model) === null)
                ->keyBy(fn (Model $model) => (string) $model->getKey());

            foreach ($group as $tombstone) {
                if ($model = $models->get($tombstone->model_id)) {
                    (new RestoreToFeed)->tombstone($tombstone, $model);
                    $restored++;
                }
            }
        }

        return $restored;
    }

    /**
     * Tombstone the missing keys of one alias, when the alias is a Feedable
     * model class. A role whose class doesn't resolve stays an orphan: that
     * is what `--prune` is for.
     *
     * @param  list<int|string>  $ids
     */
    protected function tombstone(string $type, array $ids): int
    {
        $class = MorphResolver::classFor($type);

        if ($class === null || ! is_a($class, Model::class, true) || ! app(Feedables::class)->isFeedable($class)
            || ! TombstoneEntity::installed()) {
            return 0;
        }

        return count((new TombstoneEntity)->missing($type, $ids, approximate: true));
    }

    /** @return class-string<Meta> */
    protected function meta(): string
    {
        return config('storyfeed.models.meta', Meta::class);
    }

    protected function resolve(string $type, int|string $id): ?Model
    {
        return MorphResolver::feedable($type, $id);
    }

    /** @return ActivityBuilder<Activity> */
    protected function activityQuery(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
