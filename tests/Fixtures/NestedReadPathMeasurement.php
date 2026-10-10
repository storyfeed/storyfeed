<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;
use Workbench\App\Models\Customer;

/** Add a six-link place path to the 100K guard and compare the same reads. */
final class NestedReadPathMeasurement
{
    public static function run(): array
    {
        Relation::morphMap(['container' => NestedContainer::class]);
        NestedContainer::install();
        $chain = [];
        for ($i = 0; $i < 6; $i++) {
            $parent = $chain === [] ? null : end($chain);
            $chain[] = NestedContainer::create(['name' => "Container {$i}", 'parent_type' => $parent === null ? null : 'container', 'parent_id' => $parent?->id]);
        }
        // ReadPathHistory has already written the direct participant rows.
        $participants = SyncParticipants::table();
        $target = Customer::firstOrFail();
        $read = function () use ($target): array {
            $queries = [
                'live' => fn () => Storyfeed::feed()->get()->toArray(),
                'involving' => fn () => Storyfeed::feed()->involving($target)->get()->toArray(),
            ];
            $result = [];
            foreach ($queries as $name => $query) {
                $query();
                $times = [];
                for ($i = 0; $i < 7; $i++) {
                    $start = hrtime(true);
                    $query();
                    $times[] = (hrtime(true) - $start) / 1e6;
                }
                sort($times);
                $result[$name.'_p50_ms'] = $times[3];
            }

            return $result;
        };
        $before = $read();
        $root = end($chain);
        // Existing target snapshots stand in for a target declaring parent().
        Snapshot::where('model_type', 'customer')->get()->each(function ($snapshot) use ($root): void {
            $snapshot->update(['meta' => [...($snapshot->meta ?? []), 'parent' => ['type' => 'container', 'id' => $root->id]]]);
        });
        foreach (array_reverse($chain) as $i => $parent) {
            DB::table($participants)->insertUsing(['activity_id', 'role', 'entity_type', 'entity_id', 'distance', 'published_at'],
                DB::table('feed_activities')->selectRaw("id, 'ancestor', 'container', ?, ?, published_at", [(string) $parent->id, $i + 1]));
        }
        $after = $read();
        $writes = [];
        $leaf = NestedContainer::create(['name' => 'Task', 'parent_type' => 'container', 'parent_id' => $root->id]);
        foreach ([0, 10] as $cap) {
            config()->set('storyfeed.ancestors.max_depth', $cap);
            $times = [];
            $counts = [];
            for ($i = 0; $i < 30; $i++) {
                $start = hrtime(true);
                $activity = Storyfeed::activity()->anonymously()->action('complete', $leaf)->publish();
                $times[] = (hrtime(true) - $start) / 1e6;
                $counts[] = DB::table($participants)->where('activity_id', $activity->id)->count();
            }
            sort($times);
            $writes[$cap === 0 ? 'direct' : 'nested'] = ['rows_per_activity' => array_sum($counts) / count($counts),
                'p50_ms' => $times[15], 'mean_ms' => array_sum($times) / count($times)];
        }
        config()->set('storyfeed.ancestors.max_depth', 10);
        $report = ['size' => Activity::count() - 60, 'levels' => 6, 'before' => $before, 'after' => $after, 'writes' => $writes];
        if ($path = env('STORYFEED_NESTED_REPORT')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $report;
    }
}
