<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\RebuildGroupingBursts;
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
        {--rebuild-bursts : Replace calendar groups with deterministic chronological bursts (pause publishers first)}
        {--writers-paused : Confirm ALL publishers, workers and schedulers are paused for the full rebuild}
        {--resume : Continue an interrupted --rebuild-bursts from its committed cursor}
        {--restart : Discard interrupted --rebuild-bursts progress and replay all history}
        {--release : First release composite members whose parent no longer exists (one-off repair)}';

    protected $description = 'Select the winning grouping axis for activities (backfill/repair)';

    public function handle(): int
    {
        $window = $this->option('window');
        $rehash = (bool) $this->option('rehash');

        if (! $this->option('rebuild-bursts') && ($this->option('resume') || $this->option('restart') || $this->option('writers-paused'))) {
            $this->error('--resume, --restart and --writers-paused require --rebuild-bursts.');

            return self::FAILURE;
        }
        // Live reads no winners while curation is off, so stamping them
        // would be work nothing reads, and stamps that suggest it is on.
        // Releasing a dangling claim is still a repair: composites render
        // either way.
        if (! config('storyfeed.grouping.curate', true)) {
            if ($this->option('release')) {
                $this->release();
            }

            $this->info('Curation is off (storyfeed.grouping.curate is false), so there is nothing to curate: Live reads no winners while it is off.');

            return self::SUCCESS;
        }

        if ($this->option('rebuild-bursts')) {
            if ($window !== null || $this->option('release')) {
                $this->error('--rebuild-bursts requires all history; run without --window or --release.');

                return self::FAILURE;
            }
            if (! $this->option('writers-paused')) {
                $this->error('Pause ALL publishers, queue workers and schedulers, keep the site in maintenance, then pass --writers-paused.');

                return self::FAILURE;
            }
            $this->warn('Keep readers and ALL writers paused until completion, including after interruption. Maintenance mode alone does not stop queue workers.');
            $bar = $this->output->createProgressBar();
            $phase = null;
            try {
                $stats = (new RebuildGroupingBursts)(
                    resume: (bool) $this->option('resume'),
                    restart: (bool) $this->option('restart'),
                    progress: function (int $done, int $total, string $current) use ($bar, &$phase): void {
                        if ($current !== $phase) {
                            if ($phase !== null) {
                                $bar->finish();
                                $this->newLine();
                            }
                            $phase = $current;
                            $this->line($current === 'replay' ? 'Replaying burst memberships:' : 'Stamping winners:');
                            $bar->start($total);
                        }
                        $bar->setProgress($done);
                    },
                );
            } catch (RuntimeException $error) {
                $this->newLine();
                $this->error($error->getMessage());

                return self::FAILURE;
            }
            if ($phase !== null) {
                $bar->finish();
                $this->newLine();
            }
            MaintenanceHistory::record('curate', $stats);
            $count = $stats['processed'];
            $this->info("Rebuilt bursts and curated {$count} activities.");

            return self::SUCCESS;
        }

        if ($this->option('release')) {
            $this->release();
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

    protected function release(): void
    {
        $released = (new ReleaseComposite)->dangling();

        $this->info("Released {$released} composite ".str('member')->plural($released).' whose parent no longer exists.');
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
