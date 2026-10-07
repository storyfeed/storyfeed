<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Storyfeed\FeedBuilder;

final class ReadPathMeasurement
{
    /** @param class-string<FeedBuilder> $builder */
    public static function run(string $builder = FeedBuilder::class, ?callable $onRead = null): array
    {
        $connection = DB::connection();
        $bytes = match (DB::getDriverName()) {
            'pgsql' => (int) DB::selectOne('select pg_database_size(current_database()) as bytes')->bytes,
            'mysql', 'mariadb' => (int) DB::selectOne('select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()')->bytes,
            default => (int) DB::selectOne('pragma page_count')->page_count * (int) DB::selectOne('pragma page_size')->page_size,
        };
        $report = ['driver' => DB::getDriverName(), 'activities' => DB::table('feed_activities')->count(), 'groupings' => DB::table('feed_groupings')->count(), 'database_bytes' => $bytes, 'history_days' => 120, 'php_version' => PHP_VERSION, 'time_boundary' => 'FeedBuilder::get plus complete FeedPage::toArray; excludes HTTP/network', 'repetitions' => 5, 'reads' => []];
        if (DB::getDriverName() === 'pgsql') {
            $report['statistics'] = DB::select("select relname, reltuples from pg_class where relname in ('feed_activities', 'feed_groupings')");
        }
        foreach ([false, true] as $curate) {
            config()->set('storyfeed.grouping.curate', $curate);
            foreach (['live', 'summary'] as $mode) {
                $cursor = null;
                foreach ([1, 2] as $page) {
                    $times = [];
                    $buildTimes = [];
                    $queries = [];
                    // Warm the read once; measure complete payload construction, not only SQL.
                    (new $builder)->{$mode}()->limit(30)->cursor($cursor)->get()->toArray();
                    foreach (range(1, 5) as $rep) {
                        $connection->flushQueryLog();
                        $connection->enableQueryLog();
                        $start = hrtime(true);
                        $result = (new $builder)->{$mode}()->limit(30)->cursor($cursor)->get();
                        $buildTimes[] = (hrtime(true) - $start) / 1e6;
                        $payload = $result->toArray();
                        $times[] = (hrtime(true) - $start) / 1e6;
                        $queries = $connection->getQueryLog();
                        $connection->disableQueryLog();
                    }
                    sort($times);
                    usort($queries, fn ($a, $b) => $b['time'] <=> $a['time']);
                    $slowest = array_slice($queries, 0, 5);
                    if (env('STORYFEED_READ_PROFILE')) {
                        foreach ($slowest as &$query) {
                            $prefix = match (DB::getDriverName()) {
                                'sqlite' => 'explain query plan ',
                                'pgsql' => 'explain (analyze, buffers, summary) ',
                                default => 'explain ',
                            };
                            $query['plan'] = DB::select($prefix.$query['query'], $query['bindings']);
                        }
                        unset($query);
                    }
                    $report['reads'][] = ['mode' => $mode, 'curate' => $curate, 'page' => $page, 'p50_ms' => $times[2], 'p95_ms' => $times[4], 'samples_ms' => $times, 'build_ms' => $buildTimes, 'sql_ms' => array_sum(array_column($queries, 'time')), 'queries' => count($queries), 'slowest' => $slowest];
                    if ($onRead !== null) {
                        $onRead($report);
                    }
                    $cursor = $payload['next_cursor'];
                    if ($payload['items'] === [] || $cursor === null) {
                        throw new \RuntimeException('The scale fixture must have two full pages.');
                    }
                }
            }
        }

        return $report;
    }
}
