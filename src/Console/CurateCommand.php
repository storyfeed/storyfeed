<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Models\Activity;
use Storyfeed\Support\SyncToken;

/**
 * Backfill and repair curation. Curation runs inline at publish, so this is
 * for adopters upgrading into the `winner` column, for imported rows, and
 * for re-running after a policy change.
 *
 * Idempotent by construction: re-running produces identical stamps.
 */
class CurateCommand extends Command
{
    protected $signature = 'storyfeed:curate
        {--window= : Only activities published within this many days}
        {--rehash : Re-run the grouping strategy first, so rows adopt newly added axes}';

    protected $description = 'Select the winning grouping axis for activities (backfill/repair)';

    public function handle(): int
    {
        $window = $this->option('window');
        $rehash = (bool) $this->option('rehash');

        $model = config('storyfeed.models.activity', Activity::class);

        $query = $model::query()
            ->when($window !== null, fn ($q) => $q->where('published_at', '>=', now()->subDays((int) $window)))
            ->orderBy('id');

        $write = new WriteGroupings;
        $curate = new CurateCluster;
        $count = 0;

        $query->chunkById(500, function ($activities) use ($write, $curate, $rehash, &$count) {
            foreach ($activities as $activity) {
                if ($rehash) {
                    // Candidate hashes are written at publish; a strategy
                    // that has since learned a new axis needs them refreshed
                    // before deciding — otherwise old rows can never win it.
                    $write($activity);
                }

                $curate($activity);
                $count++;
            }
        });

        if ($rehash && $count > 0) {
            // A rehash can rewrite settled group identities wholesale —
            // the resync signal, same as the bundle backfill.
            SyncToken::bump();
        }

        $this->info("Curated {$count} activities.");

        return self::SUCCESS;
    }
}
