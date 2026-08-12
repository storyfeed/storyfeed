<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\BundleComposites;
use Storyfeed\Models\Batch;
use Storyfeed\Support\SyncToken;

/**
 * Backfill bundling: sweep CLOSED batches through the composite bundler —
 * the migration path for a model that adopts Collectable after history
 * exists. Automatic bundling is future-only (it fires at batch close and
 * closed batches are never revisited); this command is the explicit walk
 * backward.
 *
 * Reaches only history recorded since the batch infrastructure existed
 * (pre-batch rows have no window to bundle from), and it KNOWINGLY
 * reshuffles settled days — historical repeat groups become composites,
 * with new node ids. Run it with intent; renderers will re-render.
 *
 * Idempotent: claimed members are skipped, so re-running is a no-op.
 */
class BundleCommand extends Command
{
    protected $signature = 'storyfeed:bundle {--window= : Only batches closed within this many days}';

    protected $description = 'Bundle collectable runs in closed batches into composite stories (backfill)';

    public function handle(): int
    {
        $window = $this->option('window');

        $model = config('storyfeed.models.batch', Batch::class);

        $bundle = new BundleComposites;
        $batches = 0;
        $minted = 0;

        $model::query()
            ->whereNotNull('closed_at')
            ->when($window !== null, fn ($q) => $q->where('closed_at', '>=', now()->subDays((int) $window)))
            ->orderBy('id')
            ->chunkById(100, function ($closed) use ($bundle, &$batches, &$minted) {
                foreach ($closed as $batch) {
                    $minted += $bundle($batch);
                    $batches++;
                }
            });

        if ($minted > 0) {
            // Settled history was rewritten: accumulated clients cannot
            // reconcile this (docs/payload.md, the third case) — the token
            // tells them to resync.
            SyncToken::bump();
        }

        $this->info("Swept {$batches} closed batch(es); minted {$minted} composite(s).");

        return self::SUCCESS;
    }
}
