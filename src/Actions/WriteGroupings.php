<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;

/**
 * Write one candidate grouping hash per axis for an activity. Called at
 * publish time, and by the trickle for legacy/imported rows so ungrouped
 * activities converge into the grouped read path.
 */
class WriteGroupings
{
    /**
     * @param  bool  $inserted  the activity was inserted by the publish that
     *                          is calling, so no stale bucket can exist yet
     */
    public function __invoke(Activity $activity, bool $inserted = false): void
    {
        $grouping = config('storyfeed.models.grouping', Grouping::class);

        // A composite parent or member is CLAIMED: its story is the
        // composite, so it never (re)enters inference — without this guard
        // the trickle would hand claimed rows back to the axes. A member's
        // claim names its parent's uid; the parent's names its own.
        $claim = $grouping::query()
            ->where('activity_id', $activity->getKey())
            ->where('bucket', 'composite')
            ->value('hash');

        if ($claim !== null && $claim !== $activity->uid) {
            return;
        }

        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));

        $hashes = self::admitted($strategy->hashes($activity), $claim !== null);

        // A fresh publish has no rows to update, so every axis goes in one
        // multi-row insert, as many() writes new rows. The four partition
        // axes made this matter: a SELECT and an INSERT per axis would be
        // sixteen statements inside the publish transaction, and on Postgres
        // the cost is planning per statement, not row width.
        if ($inserted) {
            $now = now();

            if ($hashes !== []) {
                $grouping::query()->insert(array_map(
                    fn (string $axis, string $hash) => ['activity_id' => $activity->getKey(), 'bucket' => $axis, 'hash' => $hash, 'created_at' => $now, 'updated_at' => $now],
                    array_keys($hashes),
                    $hashes,
                ));
            }

            return;
        }

        foreach ($hashes as $axis => $hash) {
            $grouping::query()->updateOrCreate(
                ['activity_id' => $activity->getKey(), 'bucket' => $axis],
                ['hash' => $hash],
            );
        }

        // An activity edited to drop a role stops emitting that axis; without
        // this its old bucket would linger and keep grouping it forever.
        // The batch bucket is exempt: batch membership is written by the
        // publish path, not the strategy, so it is never in $hashes — the
        // delete would otherwise destroy it on every re-run (trickle!).
        // A row the calling publish just inserted has nothing to drop (it
        // returned above), and on InnoDB deleting nothing still locks the
        // index's tail, where every concurrent publish inserts (see
        // SyncParticipants).
        $grouping::query()
            ->where('activity_id', $activity->getKey())
            ->whereNotIn('bucket', app(StoryfeedManager::class)->rowBackedBuckets())
            ->when($hashes !== [], fn ($query) => $query->whereNotIn('bucket', array_keys($hashes)))
            ->delete();
    }

    /**
     * The same for many activities at once, in a handful of queries rather
     * than several per activity: what a tombstone's repoint needs, where a
     * popular entity is thousands of rows. Same rules as __invoke(): claimed
     * rows are skipped, a changed hash keeps its row (and its winner stamp),
     * and a bucket the strategy stopped emitting is removed unless it is
     * row-backed.
     *
     * @param  iterable<Activity>  $activities
     */
    public function many(iterable $activities): void
    {
        $byKey = [];

        foreach ($activities as $activity) {
            $byKey[$activity->getKey()] = $activity;
        }

        if ($byKey === []) {
            return;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);
        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));
        $rowBacked = app(StoryfeedManager::class)->rowBackedBuckets();
        $ids = array_keys($byKey);

        $claims = $grouping::query()->whereIn('activity_id', $ids)->where('bucket', 'composite')
            ->pluck('hash', 'activity_id');

        $key = (new $grouping)->getKeyName();
        $existing = $grouping::query()->whereIn('activity_id', $ids)
            ->toBase()
            ->get([$key, 'activity_id', 'bucket', 'hash'])
            ->groupBy('activity_id');

        /** @var array<int|string, string> $updates grouping row key => new hash */
        $updates = [];
        $inserts = [];
        $deletes = [];
        $now = now();

        foreach ($byKey as $id => $activity) {
            $claim = $claims->get($id);

            if ($claim !== null && $claim !== $activity->uid) {
                continue;
            }

            $hashes = self::admitted($strategy->hashes($activity), $claim !== null);
            $own = ($existing->get($id) ?? collect())->keyBy('bucket');

            foreach ($hashes as $bucket => $hash) {
                $row = $own->get($bucket);

                if ($row === null) {
                    $inserts[] = ['activity_id' => $id, 'bucket' => $bucket, 'hash' => $hash, 'created_at' => $now, 'updated_at' => $now];
                } elseif ($row->hash !== $hash) {
                    $updates[$row->{$key}] = $hash;
                }
            }

            foreach ($own as $bucket => $row) {
                if (! isset($hashes[$bucket]) && ! in_array($bucket, $rowBacked, true)) {
                    $deletes[] = $row->{$key};
                }
            }
        }

        $this->rehash(new $grouping, $updates);

        if ($inserts !== []) {
            $grouping::query()->insert($inserts);
        }

        if ($deletes !== []) {
            $grouping::query()->whereKey($deletes)->delete();
        }
    }

    /**
     * A COMPOSITE PARENT KEEPS ITS PARTITION ROWS. Its members are told by
     * the parent, so they stay claimed; but the parent is the telling, and
     * the digest must place it under its person's day like anything else.
     * Every other axis stays out: the parent never enters inference.
     *
     * @param  array<string, string>  $hashes
     * @return array<string, string>
     */
    private static function admitted(array $hashes, bool $parent): array
    {
        if (! $parent) {
            return $hashes;
        }

        $storyfeed = app(StoryfeedManager::class);

        return array_filter(
            $hashes,
            fn (string $bucket) => $storyfeed->axis($bucket)?->isPartition() === true,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Give many grouping rows each its own new hash, a few hundred rows per
     * statement (`CASE id WHEN … THEN …`). A row per UPDATE was a thousand
     * statements per chunk of a repoint, all inside its transaction. The
     * winner stamp is untouched, as updateOrCreate() leaves it.
     *
     * @param  array<int|string, string>  $hashes  row key => hash
     */
    private function rehash(Model $grouping, array $hashes): void
    {
        $connection = $grouping->getConnection();
        $grammar = $connection->getQueryGrammar();
        $key = $grammar->wrap($grouping->getKeyName());

        // 300 rows is 900 bindings, inside every driver's parameter limit.
        foreach (array_chunk($hashes, 300, true) as $rows) {
            $bindings = [];

            foreach ($rows as $id => $hash) {
                array_push($bindings, $id, $hash);
            }

            $cases = implode(' ', array_fill(0, count($rows), 'when ? then ?'));
            $in = implode(', ', array_fill(0, count($rows), '?'));

            $connection->update(
                'update '.$grammar->wrapTable($grouping->getTable())
                ." set {$grammar->wrap('hash')} = case {$key} {$cases} end, {$grammar->wrap('updated_at')} = ?"
                ." where {$key} in ({$in})",
                [...$bindings, $grouping->freshTimestampString(), ...array_keys($rows)],
            );
        }
    }
}
