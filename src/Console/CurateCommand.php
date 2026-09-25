<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\ReleaseComposite;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\CurationWindow;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\SyncToken;

/**
 * Backfill and repair curation. Curation runs inline at publish, so this is
 * for adopters upgrading into the `winner` column, for imported rows, and
 * for re-running after a policy change.
 *
 * Idempotent by construction: re-running produces identical stamps.
 *
 * `--release` is the one-off repair for composite members whose parent was
 * erased before ForceDeleteFromFeed and prune released them (todo 1348):
 * still claimed, so the story went on rendering from them after the parent
 * that told it was gone. It lives here and not
 * on `storyfeed:heal`, whose contract is that no missing activity ever
 * causes a write, nor on `storyfeed:prune`, which deletes, and an operator
 * who never prunes should not have to run it to get rows back. Releasing a
 * claim and re-deciding the member's clusters is grouping repair, which is
 * what this command is. It is a flag rather than part of every run because
 * it reshapes settled history and moves `sync_token`, which an hourly
 * schedule should not do unasked, and so a regression stays visible: the
 * doctor's `claims` check keeps counting until someone runs it on purpose.
 */
class CurateCommand extends Command
{
    protected $signature = 'storyfeed:curate
        {--window= : Only activities published within this many days (a verb grouped per week or month: its whole period)}
        {--rehash : Re-run the grouping strategy first, so rows adopt newly added axes}
        {--release : First release composite members whose parent no longer exists (one-off repair)}';

    protected $description = 'Select the winning grouping axis for activities (backfill/repair)';

    public function handle(): int
    {
        $window = $this->option('window');
        $rehash = (bool) $this->option('rehash');

        if ($this->option('release')) {
            $released = (new ReleaseComposite)->dangling();

            $this->info("Released {$released} composite ".str('member')->plural($released).' whose parent no longer exists.');
        }

        $model = config('storyfeed.models.activity', Activity::class);

        $query = $model::query()
            ->when($window !== null, fn ($q) => CurationWindow::constrain($q, (int) $window))
            ->orderBy('id');

        $write = new WriteGroupings;
        $restamped = 0;
        $rehashed = 0;
        $curate = new CurateCluster(function (bool $changed) use (&$restamped): void {
            $restamped += (int) $changed;
        });
        $count = 0;

        $query->chunkById(500, function ($activities) use ($write, $curate, $rehash, &$count, &$rehashed) {
            foreach ($activities as $activity) {
                if ($rehash) {
                    // Candidate hashes are written at publish; a strategy
                    // that has since learned a new axis needs them refreshed
                    // before deciding — otherwise old rows can never win it.
                    $before = $this->hashes($activity);
                    $write($activity);
                    $rehashed += (int) ($before !== $this->hashes($activity));
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

        MaintenanceHistory::record('curate', [
            'processed' => $count,
            'restamped' => $restamped,
            'rehashed' => $rehashed,
        ]);

        $this->info("Curated {$count} activities.");

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    protected function hashes(Activity $activity): array
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query()->where('activity_id', $activity->getKey())
            ->whereNotIn('bucket', app(StoryfeedManager::class)->rowBackedBuckets())
            ->orderBy('bucket')->pluck('hash', 'bucket')->all();
    }
}
