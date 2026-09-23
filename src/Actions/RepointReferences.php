<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Facades\DB;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;

/**
 * Move every reference to one entity onto another: the role columns of all
 * seven roles, their `cached_*_id` snapshot pointers, and the participant
 * rows `involving()` reads. What a delete does to point the feed at a
 * tombstone, and what a restore does to point it back.
 *
 * Soft-deleted activities are moved too. A delete is meant to leave no trace
 * of the model's key on any row, and a restored activity must come back
 * pointing at whatever its entity is by then.
 *
 * Group hashes are built from role ids, so a moved activity's groupings are
 * rewritten, and every cluster it left or joined is re-settled once the move
 * is done. A cluster keyed on the entity keeps the same members under a new
 * hash; one keyed on the entity's TYPE loses a member, which is why the
 * re-settle is not skipped.
 *
 * CHUNKED, ONE TRANSACTION PER CHUNK, like ForceDeleteFromFeed. A popular
 * entity is thousands of writes on `feed_activities`, which carries more
 * than a dozen indexes; one transaction for all of it would hold its locks
 * for the whole run. Each chunk moves its rows, their participants and their
 * groupings together, so a failure never leaves one without the others. A
 * moved row no longer matches, so the loop converges without an exclusion
 * list.
 */
class RepointReferences
{
    public const CHUNK = 500;

    /**
     * @return int how many activities were moved
     */
    public function __invoke(string $fromType, int|string $fromId, string $toType, int|string $toId, ?int $toSnapshotId): int
    {
        $moved = [];
        /** @var array<string, array{string, string}> $clusters */
        $clusters = [];

        foreach (ActivityRoles::STORED as $role) {
            while (true) {
                $ids = $this->activities()
                    ->withTrashed()
                    ->where("{$role}_type", $fromType)
                    ->where("{$role}_id", $fromId)
                    ->limit(self::CHUNK)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $this->activities()->getConnection()->transaction(function () use ($role, $ids, $fromType, $fromId, $toType, $toId, $toSnapshotId, &$clusters) {
                    $clusters += $this->clusters($ids);

                    $this->activities()->withTrashed()->whereKey($ids)
                        ->where("{$role}_type", $fromType)
                        ->where("{$role}_id", $fromId)
                        ->update([
                            "{$role}_type" => $toType,
                            "{$role}_id" => $toId,
                            "cached_{$role}_id" => $toSnapshotId,
                        ]);

                    DB::table(SyncParticipants::table())
                        ->whereIn('activity_id', $ids)
                        ->where('role', $role)
                        ->where('entity_type', $fromType)
                        ->where('entity_id', (string) $fromId)
                        ->update(['entity_type' => $toType, 'entity_id' => (string) $toId]);

                    (new WriteGroupings)->many($this->activities()->withTrashed()->whereKey($ids)->get());

                    $clusters += $this->clusters($ids);
                });

                foreach ($ids as $id) {
                    $moved[$id] = true;
                }
            }
        }

        if ($clusters !== [] && config('storyfeed.grouping.curate', true)) {
            (new CurateCluster)->repairMany($clusters);
        }

        return count($moved);
    }

    /**
     * The curated clusters a set of activities belongs to. Row-backed
     * buckets (batches, composite claims) are not built from roles and are
     * outside curation.
     *
     * @param  list<int|string>  $ids
     * @return array<string, array{string, string}>
     */
    protected function clusters(array $ids): array
    {
        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $clusters = [];

        foreach ($grouping::query()
            ->whereIn('activity_id', $ids)
            ->whereNotIn('bucket', app(StoryfeedManager::class)->rowBackedBuckets())
            ->distinct()
            ->toBase()
            ->get(['bucket', 'hash']) as $row) {
            $clusters["{$row->bucket}\0{$row->hash}"] = [(string) $row->bucket, (string) $row->hash];
        }

        return $clusters;
    }

    /** @return ActivityBuilder<Activity> */
    protected function activities(): ActivityBuilder
    {
        return DeleteFromFeed::query();
    }
}
