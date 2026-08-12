<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;

/**
 * Mixed shape fingerprints within one model type mean some snapshots predate
 * the current toFeed() structure — renderers may hit missing keys. Cheap
 * data-only check (no model loading); the trickle is the healer.
 */
class SnapshotShapes extends Check
{
    public function name(): string
    {
        return 'shapes';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $table = $this->table('snapshots');

        // Missing column is Columns'  finding; querying it here would just
        // crash the doctor mid-diagnosis — which is worse than no check at
        // all, because it takes the other eight down with it.
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'shape')) {
            return;
        }

        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);

        $mixed = $snapshot::query()
            ->selectRaw('model_type, count(distinct shape) as shapes, sum(case when shape is null then 1 else 0 end) as unshaped')
            ->groupBy('model_type')
            ->havingRaw('count(distinct shape) > 1 or sum(case when shape is null then 1 else 0 end) > 0')
            ->get();

        foreach ($mixed as $row) {
            yield Finding::warning(
                'shapes.mixed',
                "Snapshots of `{$row->model_type}` carry mixed shape fingerprints — some predate the current "
                .'toFeed() structure. storyfeed:trickle converges them (or run it now).',
                ['model_type' => $row->model_type, 'shapes' => (int) $row->shapes],
            );
        }
    }
}
