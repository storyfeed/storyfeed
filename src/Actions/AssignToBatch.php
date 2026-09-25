<?php

namespace Storyfeed\Actions;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;

/**
 * Link a just-published activity to its actor's event-time batch — an earlier
 * implementation's open-window pattern, generalized. Run by the `batch` story
 * middleware ({@see \Storyfeed\Middleware\Batch}) after the row is stored,
 * in a transaction of its own; the developer never sees it (atomic
 * activities are recorded, the rest is handled).
 *
 * A batch is a SITTING and `closes_at` is when it ends: each member pushes
 * it to its own `published_at` plus its verb's window, never backwards, so a
 * verb batched `within: '1 hour'` keeps the sitting open longer than one
 * batched within five minutes. An activity joins when it was published
 * before `closes_at`.
 *
 * That is enforced HERE, lazily: if the next event is at or beyond an open
 * batch's `closes_at`, the batch is closed (firing BatchClosed) and a fresh
 * one opened. Feeds and batch membership are therefore correct
 * with zero scheduling; storyfeed:close-batches exists only so BatchClosed
 * fires promptly for actors who walked away.
 *
 * Anonymous activities are never batched: a null actor means the actor is
 * genuinely unknown, so there is no session to attribute. A named Party
 * (system, integration) batches like any other actor.
 *
 * Every lock here is on a row that exists, found by its primary key (todo
 * 1339). The actor's row in `feed_batch_locks` is upserted first, so two
 * publishes by one actor queue on it: the second reads the first one's
 * batch and joins it, where it used to find nothing to lock and open its
 * own. That row also lists the actor's open batches, so the candidates are
 * locked by key rather than by searching `feed_batches`. A locking search
 * that finds nothing still locks the gap where the row would go, and on
 * MySQL and MariaDB two new actors whose keys share that gap each held it,
 * each tried to insert into it, and one publish died with a deadlock.
 *
 * The lock is taken here, at the batch decision. Like any row lock it is
 * held until the enclosing transaction commits, which is what makes the
 * second publish see the first one's batch.
 */
class AssignToBatch
{
    /**
     * @param  CarbonInterval|null  $window  this verb's window; null is `grouping.batch.quiet_minutes`
     * @return Batch|null the batch the activity joined
     */
    public function __invoke(Activity $activity, ?CarbonInterval $window = null): ?Batch
    {
        if (! config('storyfeed.grouping.batch.enabled', true)) {
            return null;
        }

        if ($activity->actor_type === null) {
            return null;
        }

        $publishedAt = $activity->published_at;

        $batch = $this->resolveOpenBatch($activity, $publishedAt);

        $closesAt = $publishedAt->copy()->add($window ?? self::configuredWindow());

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $grouping::query()->updateOrCreate(
            ['activity_id' => $activity->getKey(), 'bucket' => 'batch'],
            ['hash' => $batch->uid],
        );

        $batch->forceFill([
            'activities_count' => $batch->activities_count + 1,
            // Out-of-order members must not move the window backwards.
            'last_activity_at' => $batch->last_activity_at?->max($publishedAt) ?? $publishedAt,
            'closes_at' => $batch->closes_at?->max($closesAt) ?? $closesAt,
        ])->save();

        return $batch;
    }

    protected function resolveOpenBatch(Activity $activity, CarbonInterface $publishedAt): Batch
    {
        $model = config('storyfeed.models.batch', Batch::class);

        $lock = $this->lockActor($activity);

        $listed = json_decode((string) $lock->value('open_batches'), true) ?: [];

        // Every open batch the actor has, locked by key; the list may still
        // name batches the sweeper has closed since, which drop out here.
        /** @var Collection<int, Batch> $batches */
        $batches = $listed === [] ? collect() : $model::query()
            ->whereKey($listed)
            ->open()
            ->lockForUpdate()
            ->get();

        // Late arrivals never reopen closed batches or move an opening
        // backwards; without a compatible open window, they start a separate
        // batch.
        /** @var Batch|null $open */
        $open = $batches
            ->filter(fn (Batch $batch) => $batch->opened_at->lte($publishedAt))
            ->sortByDesc(fn (Batch $batch) => [$batch->opened_at->format('Y-m-d H:i:s.u'), $batch->getKey()])
            ->first();

        if ($open !== null && $this->withinWindow($open, $publishedAt)) {
            $this->list($lock, $listed, $batches);

            return $open;
        }

        if ($open !== null) {
            $this->close($open, Carbon::now());
            $batches = $batches->reject(fn (Batch $batch) => $batch->is($open));
        }

        $batch = $model::query()->create([
            'actor_type' => $activity->actor_type,
            'actor_id' => $activity->actor_id,
            'opened_at' => $publishedAt,
        ]);

        $this->list($lock, $listed, $batches->push($batch));

        return $batch;
    }

    /**
     * An upsert, not a SELECT … FOR UPDATE: the first publish for an actor
     * has no row to lock, which is the whole race. Inserting and updating
     * both lock the row, and a concurrent insert of the same key waits on it.
     * No cache driver and no advisory lock, so it holds wherever the
     * database's own row locks hold. The read after it is a locking read so
     * that MySQL returns the committed list, not the transaction's snapshot.
     */
    protected function lockActor(Activity $activity): Builder
    {
        $key = [
            'actor_type' => $activity->actor_type,
            'actor_id' => (string) $activity->actor_id,
        ];

        $table = fn () => $activity->getConnection()
            ->table(config('storyfeed.tables.batch_locks', 'feed_batch_locks'));

        $table()->upsert([...$key, 'locked_at' => Carbon::now()], array_keys($key), ['locked_at']);

        return $table()->where($key)->lockForUpdate();
    }

    /**
     * Keep the actor's list to the batches still open, and write it only
     * when that changed, so joining an open batch costs no extra write.
     *
     * @param  list<int|string>  $listed
     * @param  Collection<int, Batch>  $open
     */
    protected function list(Builder $lock, array $listed, Collection $open): void
    {
        $ids = $open->map(fn (Batch $batch) => $batch->getKey())->sort()->values()->all();

        $before = $listed;
        sort($before);

        if ($ids !== $before) {
            (clone $lock)->update(['open_batches' => json_encode($ids)]);
        }
    }

    protected function withinWindow(Batch $batch, CarbonInterface $publishedAt): bool
    {
        return $publishedAt->lt(self::closesAt($batch));
    }

    /**
     * When the sitting ends. A batch opened before `closes_at` existed, and
     * missed by the migration's backfill, ends where it always did: last
     * seen plus the configured window.
     */
    public static function closesAt(Batch $batch): CarbonInterface
    {
        return $batch->closes_at
            ?? ($batch->last_activity_at ?? $batch->opened_at)->copy()->add(self::configuredWindow());
    }

    public static function configuredWindow(): CarbonInterval
    {
        return CarbonInterval::minutes((int) config('storyfeed.grouping.batch.quiet_minutes', 10));
    }

    protected function close(Batch $batch, CarbonInterface $now): void
    {
        (new CloseBatch)($batch, $now);
    }
}
