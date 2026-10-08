<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Tests\Fixtures\LegacyParticipantsUpgrade;

it('benchmarks the old and set-based participants upgrades on 300K participants', function () {
    $activities = 75_000;
    $name = 'feed_participants';
    $report = [
        'engine' => DB::getDriverName(),
        'version' => DB::selectOne('select version() as version')->version,
        'activities' => $activities,
        'participants' => $activities * 4,
        // Four direct roles, target/context sharing a project on 80% of
        // activities: 60K redundant rows (20% of all participants).
        'duplicates' => 60_000,
        'runs' => [],
    ];
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries++;
    });
    foreach (['legacy' => new LegacyParticipantsUpgrade, 'set_based' => include __DIR__.'/../../database/migrations/add_ancestors_to_feed_participants_table.php.stub'] as $label => $migration) {
        Schema::drop($name);
        (include __DIR__.'/../../database/migrations/create_feed_participants_table.php.stub')->up();
        for ($start = 1; $start <= $activities; $start += 250) {
            $rows = [];
            for ($activity = $start; $activity < $start + 250; $activity++) {
                foreach (['actor', 'object', 'target', 'context'] as $slot => $role) {
                    $rows[] = [
                        'id' => ($activity - 1) * 4 + $slot + 1,
                        'activity_id' => $activity,
                        'role' => $role,
                        'entity_type' => ['user', 'task', 'project', 'project'][$slot],
                        'entity_id' => (string) match ($slot) {
                            0 => $activity % 500 + 1,
                            1 => $activity,
                            2 => $activity % 100 + 1,
                            3 => $activity % 5 === 0 ? $activity % 100 + 101 : $activity % 100 + 1,
                        },
                        'published_at' => '2026-10-08 12:00:00',
                    ];
                }
            }
            DB::table($name)->insert($rows);
        }
        $queries = 0;
        $start = hrtime(true);
        $migration->up();
        $seconds = (hrtime(true) - $start) / 1e9;
        $report['runs'][$label] = ['seconds' => $seconds, 'queries' => $queries];
        $report['runs'][$label]['survivors'] = (array) DB::table($name)
            ->selectRaw('count(*) as participants, sum(id) as ids, sum(distance) as distances')->first();
        file_put_contents(env('STORYFEED_PARTICIPANTS_REPORT', 'build/participants-upgrade.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fwrite(STDERR, json_encode([$label => $report['runs'][$label]])."\n");
        expect(DB::table($name)->count())->toBe(240_000)
            ->and(DB::table($name)->where('distance', 0)->count())->toBe(240_000);
        if ($label === 'set_based') {
            expect($seconds)->toBeLessThan(60);
        }
    }
    expect($report['runs']['set_based']['survivors'])->toBe($report['runs']['legacy']['survivors']);
})->skip(fn () => ! env('STORYFEED_PARTICIPANTS_BENCH'), 'Opt in with STORYFEED_PARTICIPANTS_BENCH=1 on MariaDB or PostgreSQL.');
