<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;

/**
 * `->keepLatest()` seen from the rows. A superseded row is one soft-deleted
 * with a live row on its (object, verb) published at or after it: what a
 * publish that keeps the latest leaves behind.
 *
 *  - WARNING `keep_latest.split`: a verb that keeps the latest has keys with
 *    superseded rows AND more than one live row, so it was published both
 *    ways: before the declaration, or by something that writes rows
 *    without publishing. The feed shows every live row until the next
 *    publish on the key supersedes them. A verb declared `within:` a window
 *    is skipped, because live rows outside the window are what it keeps.
 *  - WARNING `keep_latest.never_supersedes`: a verb that keeps the latest per
 *    object has more live rows than `storyfeed.doctor.keep_latest_min_rows`
 *    (50; null turns the finding off) and none superseded. Every publish
 *    has had a new object, so nothing was ever replaced and the feed floods
 *    silently; `per: 'target'` or `per: 'context'` keys on what repeats. A
 *    verb declared `within:` a window, or keyed on no object,
 *    is skipped.
 *  - INFO `keep_latest.undeclared`: superseded rows for a verb with no
 *    declaration, left by the call-site `replace()` that no longer exists.
 *    The fix is the declaration, printed by `--stubs`.
 */
class KeepLatest extends Check
{
    public function name(): string
    {
        return 'keep_latest';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        try {
            $storyfeed->ensureStoriesCompiled();
        } catch (StoryMisconfigured) {
            return;
        }

        $superseded = $this->superseded($storyfeed);

        yield from $this->split($storyfeed, $superseded);
        yield from $this->neverSupersedes($storyfeed, $superseded);
        yield from $this->undeclared($storyfeed, $superseded);
    }

    /**
     * Live rows per object type and verb above the floor, the type null for
     * an object-less row, and a tombstoned row under its former type.
     *
     * @return list<array{type: string|null, verb: string, count: int}>
     */
    protected function live(int $floor): array
    {
        $model = $this->activities()->getModel();
        $table = $model->getTable();
        $query = $model->getConnection()->table("{$table} as live");
        $objectType = $this->objectTypeOf($query, 'live');

        return $query
            ->whereNull('live.deleted_at')
            ->whereNotNull('live.object_id')
            ->selectRaw("{$objectType} as object_type, live.verb, count(*) as aggregate")
            ->groupByRaw("{$objectType}, live.verb")
            ->havingRaw('count(*) > ?', [$floor])
            ->orderBy('live.verb')->orderByRaw($objectType)
            ->get()
            ->map(fn (object $row) => [
                'type' => $row->object_type === null ? null : (string) $row->object_type,
                'verb' => (string) $row->verb,
                'count' => (int) $row->aggregate,
            ])->values()->all();
    }

    /**
     * @param  list<array{type: string|null, verb: string, count: int}>  $superseded
     * @return iterable<Finding>
     */
    protected function neverSupersedes(StoryfeedManager $storyfeed, array $superseded): iterable
    {
        $floor = config('storyfeed.doctor.keep_latest_min_rows', 50);

        if ($floor === null || $storyfeed->storyKeepLatest() === []) {
            return;
        }

        $replaced = [];

        foreach ($superseded as $row) {
            $replaced[($row['type'] ?? '*').'.'.$row['verb']] = true;
        }

        foreach ($this->live((int) $floor) as $row) {
            $declared = $storyfeed->keepLatest($row['type'], $row['verb']);

            if ($declared === null || $declared['within'] !== null || ! in_array('object', $declared['per'], true)
                || isset($replaced[($row['type'] ?? '*').'.'.$row['verb']])) {
                continue;
            }

            $on = $row['type'] === null ? "`{$row['verb']}`" : "`{$row['type']}.{$row['verb']}`";

            yield Finding::warning(
                'keep_latest.never_supersedes',
                "{$on} keeps the latest per ".implode(', ', $declared['per']).', but its '.number_format($row['count'])
                .' live rows have none superseded: each publish has had a new object, so nothing is replaced and '
                ."the feed floods. Key on what repeats instead, `->keepLatest(per: 'target')` or "
                ."`->keepLatest(per: 'context')`.",
                ['type' => $row['type'], 'verb' => $row['verb'], 'count' => $row['count']],
            );
        }
    }

    /**
     * Superseded rows per object type and verb, the type null for an
     * object-less row, and a tombstoned row under its former type.
     *
     * @return list<array{type: string|null, verb: string, count: int}>
     */
    protected function superseded(StoryfeedManager $storyfeed): array
    {
        $model = $this->activities()->getModel();
        $table = $model->getTable();
        $query = $model->getConnection()->table("{$table} as superseded");
        $objectType = $this->objectTypeOf($query, 'superseded');

        // The newest live row per key, computed once and only for the keys
        // that hold a soft-deleted row. A correlated subquery per deleted row
        // would walk a tombstoned object's whole history for each of them.
        $newest = $model->getConnection()->table("{$table} as live")
            ->select('live.object_type', 'live.object_id', 'live.verb')
            ->selectRaw('max(live.published_at) as published_at')
            ->whereNull('live.deleted_at')
            ->whereExists(fn (Builder $gone) => $gone->selectRaw('1')->from("{$table} as gone")
                ->whereNotNull('gone.deleted_at')
                ->whereColumn('gone.object_type', 'live.object_type')
                ->whereColumn('gone.object_id', 'live.object_id')
                ->whereColumn('gone.verb', 'live.verb'))
            ->groupBy('live.object_type', 'live.object_id', 'live.verb');

        $rows = $query
            ->joinSub($newest, 'newest', function (JoinClause $join) {
                $join->on('newest.object_type', '=', 'superseded.object_type')
                    ->on('newest.object_id', '=', 'superseded.object_id')
                    ->on('newest.verb', '=', 'superseded.verb')
                    ->on('superseded.published_at', '<=', 'newest.published_at');
            })
            ->whereNotNull('superseded.deleted_at')
            ->whereNotNull('superseded.object_id')
            ->selectRaw("{$objectType} as object_type, superseded.verb, count(*) as aggregate")
            ->groupByRaw("{$objectType}, superseded.verb")
            ->orderBy('superseded.verb')->orderByRaw($objectType)
            ->get();

        return $rows->map(fn (object $row) => [
            'type' => $row->object_type === null ? null : (string) $row->object_type,
            'verb' => (string) $row->verb,
            'count' => (int) $row->aggregate,
        ])->values()->all();
    }

    /**
     * @param  list<array{type: string|null, verb: string, count: int}>  $superseded
     * @return iterable<Finding>
     */
    protected function split(StoryfeedManager $storyfeed, array $superseded): iterable
    {
        foreach ($superseded as $row) {
            $declared = $storyfeed->keepLatest($row['type'], $row['verb']);

            if ($declared === null || $declared['within'] !== null) {
                continue;
            }

            $keys = $this->splitKeys($row['type'], $row['verb'], $declared['per']);

            if ($keys === 0) {
                continue;
            }

            $on = $row['type'] === null ? "`{$row['verb']}`" : "`{$row['type']}.{$row['verb']}`";

            yield Finding::warning(
                'keep_latest.split',
                "{$on} keeps the latest per ".implode(', ', $declared['per']).', but '.number_format($keys)
                .' '.($keys === 1 ? 'key has' : 'keys have').' superseded rows and more than one live row, so it was '
                .'published both ways: before `->keepLatest()` was declared, or by something writing rows without '
                .'publishing. The feed shows every live row on those keys until the next publish on each supersedes '
                .'the older ones.',
                ['type' => $row['type'], 'verb' => $row['verb'], 'keys' => $keys],
            );
        }
    }

    /**
     * Keys, on the declared roles, holding a superseded row and more than
     * one live one. Superseded here is any soft-deleted row on the key: the
     * key is already one the verb keeps the latest on.
     *
     * @param  list<string>  $per
     */
    protected function splitKeys(?string $type, string $verb, array $per): int
    {
        $columns = [];

        foreach ($per as $role) {
            array_push($columns, "{$role}_type", "{$role}_id");
        }

        $query = $this->activities()->withTrashed()->toBase()
            ->where('verb', $verb)
            ->when($type === null, fn (Builder $query) => $query->whereNull('object_type'))
            ->when($type !== null, fn (Builder $query) => $query->where('object_type', $type));

        foreach ($columns as $column) {
            $query->whereNotNull($column);
        }

        $live = 'sum(case when deleted_at is null then 1 else 0 end)';
        $gone = 'sum(case when deleted_at is null then 0 else 1 end)';

        $keys = $query->select($columns)
            ->groupBy($columns)
            ->havingRaw("{$live} > 1")
            ->havingRaw("{$gone} > 0");

        return $query->newQuery()->fromSub($keys, 'split_keys')->count();
    }

    /**
     * @param  list<array{type: string|null, verb: string, count: int}>  $superseded
     * @return iterable<Finding>
     */
    protected function undeclared(StoryfeedManager $storyfeed, array $superseded): iterable
    {
        foreach ($superseded as $row) {
            if ($storyfeed->keepLatest($row['type'], $row['verb']) !== null) {
                continue;
            }

            $key = ($row['type'] ?? '*').'.'.$row['verb'];

            yield Finding::info(
                'keep_latest.undeclared',
                number_format($row['count']).' `'.$key.'` '.($row['count'] === 1 ? 'activity was' : 'activities were')
                .' superseded by a later one on the same object, but no `->keepLatest()` reaches the verb, so the next '
                .'publish keeps every row. If only the latest should show, declare it in routes/feed.php; '
                .'`storyfeed:doctor --stubs` prints the line.',
                ['type' => $row['type'], 'verb' => $row['verb'], 'count' => $row['count']],
                Fix::make('keepLatest', $key),
            );
        }
    }
}
