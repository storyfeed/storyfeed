<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Models\Activity;

/**
 * Backfill and repair curation. Curation runs inline at publish, so this is
 * for adopters upgrading into the `winner` column, for imported rows, and
 * for re-running after a policy change.
 *
 * Idempotent by construction: re-running produces identical stamps.
 */
class CurateCommand extends Command
{
    protected $signature = 'storyfeed:curate {--window= : Only activities published within this many days}';

    protected $description = 'Select the winning grouping axis for activities (backfill/repair)';

    public function handle(): int
    {
        $window = $this->option('window');

        $model = config('storyfeed.models.activity', Activity::class);

        $query = $model::query()
            ->when($window !== null, fn ($q) => $q->where('published_at', '>=', now()->subDays((int) $window)))
            ->orderBy('id');

        $curate = new CurateCluster;
        $count = 0;

        $query->chunkById(500, function ($activities) use ($curate, &$count) {
            foreach ($activities as $activity) {
                $curate($activity);
                $count++;
            }
        });

        $this->info("Curated {$count} activities.");

        return self::SUCCESS;
    }
}
