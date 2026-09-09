<?php

namespace Storyfeed\Actions;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Storyfeed\Healing\Removals;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;

/**
 * The write side of removal evidence (read it through Healing\Removals).
 *
 * A marker is written per KEY, not per row, and only when a key loses its
 * last live story. That is the whole cost model: a bulk delete of rows that
 * are already soft-deleted writes nothing, because soft-deleting them was
 * the removal and the evidence was written then — or they were superseded,
 * which is not a removal. Rows leaving a key that keeps a live sibling
 * write nothing either, which is how supersession stays silent without a
 * special case: `supersede()` runs after the successor is saved.
 *
 * Three callers, three shapes:
 *
 * - The model's `deleted` event, one row at a time: recordFor(). Two
 *   statements per delete of a live activity.
 * - The bulk paths (`deleteFromFeed()`, `forceDeleteFromFeed()`): around(),
 *   which wraps one chunk's delete in a transaction with ONE query before it
 *   (the keys about to go empty, found with a correlated NOT EXISTS over the
 *   chunk) and ONE upsert after it. Two statements per chunk, plus marker
 *   rows for however many keys actually emptied.
 * - `PruneActivities`: prunedBefore(), which writes no markers at all. A
 *   live row past the retention window is not a deliberate removal, and a
 *   marker per expired row on a million-row sweep is the amplification this
 *   design exists to avoid. One feed_meta row per sweep records the cutoff
 *   instead; Removals::prunedBefore() reads it.
 *
 * @internal
 */
class RecordRemovals
{
    /**
     * Run a chunk's delete inside a transaction, recording the keys it empties.
     *
     * @param  list<int|string>  $ids
     * @param  Closure(): void  $delete
     */
    public function around(array $ids, Closure $delete): void
    {
        if ($ids === []) {
            return;
        }

        $this->activity()->getConnection()->transaction(function () use ($ids, $delete) {
            $emptying = $this->emptying($ids);

            $delete();

            $this->record($emptying);
        });
    }

    /**
     * Record one activity that has just been deleted, if it was the last live
     * story on its key. Called from the model's `deleted` event, after the
     * row is soft-deleted or gone, so a plain live query already excludes it.
     */
    public function recordFor(Activity $activity): void
    {
        if ($activity->object_id === null) {
            return;
        }

        $live = $activity->newQuery()
            ->where('verb', $activity->verb)
            ->where('object_type', $activity->object_type)
            ->where('object_id', $activity->object_id)
            ->exists();

        if ($live) {
            return;
        }

        $this->record([[
            'verb' => $activity->verb,
            'object_type' => $activity->object_type,
            'object_id' => $activity->object_id,
            'published_at' => $activity->published_at?->format($activity->getDateFormat()),
        ]]);
    }

    /**
     * Advance the retention watermark after a completed prune sweep. Written
     * after the sweep, never before, so it never claims more than was done;
     * monotonic, so a later sweep with a longer window cannot lower it.
     */
    public function prunedBefore(CarbonInterface $cutoff): void
    {
        $current = Removals::prunedBefore();

        if ($current !== null && $current->greaterThanOrEqualTo($cutoff)) {
            return;
        }

        Meta::query()->updateOrCreate(['key' => Removals::PRUNED_BEFORE], ['value' => $cutoff->toIso8601String()]);
    }

    /**
     * The keys that lose their last live story when these rows are deleted:
     * live rows in the set whose key has no live row outside the set.
     *
     * @param  list<int|string>  $ids
     * @return list<array{verb: string, object_type: string, object_id: int|string, published_at: string|null}>
     */
    private function emptying(array $ids): array
    {
        $activity = $this->activity();
        $table = $activity->getTable();
        $query = $activity->newQueryWithoutScopes()->getQuery();
        $grammar = $query->getGrammar();

        return $query
            ->select(["{$table}.verb", "{$table}.object_type", "{$table}.object_id"])
            ->selectRaw('max('.$grammar->wrap("{$table}.published_at").') as published_at')
            ->whereIn("{$table}.id", $ids)
            ->whereNull("{$table}.deleted_at")
            ->whereNotNull("{$table}.object_id")
            ->whereNotExists(function (Builder $sibling) use ($table, $ids) {
                $sibling->from("{$table} as live_sibling")
                    ->whereColumn('live_sibling.verb', "{$table}.verb")
                    ->whereColumn('live_sibling.object_type', "{$table}.object_type")
                    ->whereColumn('live_sibling.object_id', "{$table}.object_id")
                    ->whereNull('live_sibling.deleted_at')
                    ->whereNotIn('live_sibling.id', $ids);
            })
            ->groupBy("{$table}.verb", "{$table}.object_type", "{$table}.object_id")
            ->get()
            ->map(fn ($row) => [
                'verb' => $row->verb,
                'object_type' => $row->object_type,
                'object_id' => $row->object_id,
                'published_at' => $row->published_at,
            ])
            ->all();
    }

    /**
     * @param  list<array{verb: string, object_type: string, object_id: int|string, published_at: string|null}>  $keys
     */
    private function record(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $removedAt = $this->activity()->freshTimestampString();

        Removals::query()->upsert(
            array_map(fn (array $key) => $key + ['removed_at' => $removedAt], $keys),
            ['verb', 'object_type', 'object_id'],
            ['removed_at', 'published_at'],
        );
    }

    private function activity(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }
}
