<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;

/**
 * Link a just-published activity to its actor's current batch — an earlier
 * implementation's open-window pattern, generalized. Called inside the
 * publish transaction; the developer never sees it (atomic activities are
 * recorded, the rest is handled).
 *
 * The quiet window is enforced HERE, lazily: if the actor's open batch has
 * been quiet longer than the window, it is closed (firing BatchClosed) and
 * a fresh one opened. Feeds and batch membership are therefore correct
 * with zero scheduling; storyfeed:close-batches exists only so BatchClosed
 * fires promptly for actors who walked away.
 *
 * Anonymous activities are never batched: a null actor means the actor is
 * genuinely unknown, so there is no session to attribute. A named Party
 * (system, integration) batches like any other actor.
 */
class AssignToBatch
{
    /**
     * @return Batch|null the batch the activity joined
     */
    public function __invoke(Activity $activity): ?Batch
    {
        if (! config('storyfeed.grouping.batch.enabled', true)) {
            return null;
        }

        if ($activity->actor_type === null) {
            return null;
        }

        $now = Carbon::now();

        $batch = $this->resolveOpenBatch($activity, $now);

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $grouping::query()->updateOrCreate(
            ['activity_id' => $activity->getKey(), 'bucket' => 'batch'],
            ['hash' => $batch->uid],
        );

        $batch->forceFill([
            'activities_count' => $batch->activities_count + 1,
            'last_activity_at' => $now,
        ])->save();

        return $batch;
    }

    protected function resolveOpenBatch(Activity $activity, Carbon $now): Batch
    {
        $model = config('storyfeed.models.batch', Batch::class);

        // lockForUpdate so two concurrent publishes by the same actor
        // cannot mint two open batches — we are already inside the publish
        // transaction.
        /** @var Batch|null $open */
        $open = $model::query()
            ->open()
            ->where('actor_type', $activity->actor_type)
            ->where('actor_id', $activity->actor_id)
            ->lockForUpdate()
            ->latest('opened_at')
            ->first();

        if ($open !== null && $this->withinWindow($open, $now)) {
            return $open;
        }

        if ($open !== null) {
            $this->close($open, $now);
        }

        return $model::query()->create([
            'actor_type' => $activity->actor_type,
            'actor_id' => $activity->actor_id,
            'opened_at' => $now,
        ]);
    }

    protected function withinWindow(Batch $batch, Carbon $now): bool
    {
        $quiet = (int) config('storyfeed.grouping.batch.quiet_minutes', 10);

        $lastSeen = $batch->last_activity_at ?? $batch->opened_at;

        return $lastSeen->gt($now->copy()->subMinutes($quiet));
    }

    protected function close(Batch $batch, Carbon $now): void
    {
        $batch->forceFill(['closed_at' => $now])->save();

        if ($batch->activities_count > 0) {
            BatchClosed::dispatch($batch);

            // The burst is over — homogeneous collectable runs become
            // composite stories (docs/grouping.md).
            if (config('storyfeed.grouping.composite.auto', true)) {
                (new BundleComposites)($batch);
            }
        }
    }
}
