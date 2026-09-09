<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;

/**
 * Link a just-published activity to its actor's event-time batch — an earlier
 * implementation's open-window pattern, generalized. Called inside the
 * publish transaction; the developer never sees it (atomic activities are
 * recorded, the rest is handled).
 *
 * The quiet window is enforced HERE, lazily: if the next event is beyond
 * an open batch's event-time window, it is closed (firing BatchClosed) and
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

        $publishedAt = $activity->published_at;

        $batch = $this->resolveOpenBatch($activity, $publishedAt);

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $grouping::query()->updateOrCreate(
            ['activity_id' => $activity->getKey(), 'bucket' => 'batch'],
            ['hash' => $batch->uid],
        );

        $batch->forceFill([
            'activities_count' => $batch->activities_count + 1,
            // Out-of-order members must not move the window backwards.
            'last_activity_at' => $batch->last_activity_at?->max($publishedAt) ?? $publishedAt,
        ])->save();

        return $batch;
    }

    protected function resolveOpenBatch(Activity $activity, Carbon $publishedAt): Batch
    {
        $model = config('storyfeed.models.batch', Batch::class);

        // Lock an existing candidate inside the publish transaction. Late
        // arrivals never reopen closed batches or move an opening backwards;
        // without a compatible open window, they start a separate batch.
        /** @var Batch|null $open */
        $open = $model::query()
            ->open()
            ->where('actor_type', $activity->actor_type)
            ->where('actor_id', $activity->actor_id)
            ->where('opened_at', '<=', $publishedAt)
            ->lockForUpdate()
            ->latest('opened_at')
            ->latest('id')
            ->first();

        if ($open !== null && $this->withinWindow($open, $publishedAt)) {
            return $open;
        }

        if ($open !== null) {
            $this->close($open, Carbon::now());
        }

        return $model::query()->create([
            'actor_type' => $activity->actor_type,
            'actor_id' => $activity->actor_id,
            'opened_at' => $publishedAt,
        ]);
    }

    protected function withinWindow(Batch $batch, Carbon $publishedAt): bool
    {
        $quiet = (int) config('storyfeed.grouping.batch.quiet_minutes', 10);

        $lastSeen = $batch->last_activity_at ?? $batch->opened_at;

        return $lastSeen->gt($publishedAt->copy()->subMinutes($quiet));
    }

    protected function close(Batch $batch, Carbon $now): void
    {
        (new CloseBatch)($batch, $now);
    }
}
