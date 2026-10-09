<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Storyfeed\Actions\RebuildAncestors;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Models\Activity;

/**
 * Backfill the participants index that `involving()` reads.
 *
 * Publish-time sync covers everything recorded since the table existed; this
 * is the one-time walk backward for an install that upgraded into it. Until it
 * runs, `involving()` returns nothing for older activities — which is why
 * doctor's `participants` check names this command.
 *
 * Idempotent: each activity's rows are rewritten from its own columns, so
 * re-running converges rather than duplicating. Chunked, newest-first, so a
 * partial run leaves the most-read history correct. The --ancestors mode
 * walks current parents; adding --missing preserves every recorded path.
 */
class ParticipantsCommand extends Command
{
    protected $signature = 'storyfeed:participants
        {--chunk=500 : Activities to process per batch}
        {--missing : Only missing participants, or never-recorded paths with --ancestors}
        {--ancestors : Rebuild all recorded paths from current parents}
        {--writers-paused : Confirm all publishers, workers and schedulers are paused}
        {--resume : Continue an interrupted ancestor rebuild}
        {--restart : Discard ancestor rebuild progress and replay all history}';

    protected $description = 'Rebuild the participants index used by involving() (backfill)';

    public function handle(): int
    {
        if (! $this->option('ancestors') && ($this->option('resume') || $this->option('restart') || $this->option('writers-paused'))) {
            $this->error('--resume, --restart and --writers-paused require --ancestors.');

            return self::FAILURE;
        }
        if ($this->option('ancestors')) {
            if (! $this->option('writers-paused')) {
                $this->error('Pause ALL publishers, workers, schedulers and readers, then pass --ancestors --writers-paused (optionally --missing).');

                return self::FAILURE;
            }
            $this->warn('Keep all readers and writers paused until completion, including after interruption.');
            $bar = $this->output->createProgressBar();
            try {
                $stats = (new RebuildAncestors)(
                    resume: (bool) $this->option('resume'), restart: (bool) $this->option('restart'),
                    batchSize: (int) $this->option('chunk'),
                    progress: function (int $done, int $total) use ($bar): void {
                        $bar->setMaxSteps($total);
                        $bar->setProgress($done);
                    },
                    missing: (bool) $this->option('missing'),
                );
            } catch (RuntimeException $error) {
                $this->newLine();
                $this->error($error->getMessage());

                return self::FAILURE;
            }
            $bar->finish();
            $this->newLine();
            $operation = $this->option('missing') ? 'Backfilled missing ancestors' : 'Rebuilt ancestors';
            $this->info("{$operation} for {$stats['processed']} activities.");

            return self::SUCCESS;
        }
        $model = config('storyfeed.models.activity', Activity::class);
        $chunk = max(1, (int) $this->option('chunk'));
        $sync = new SyncParticipants;
        $table = SyncParticipants::table();

        $processed = 0;

        $model::query()
            ->withTrashed()
            ->when($this->option('missing'), fn ($query) => $query->whereNotExists(
                fn ($sub) => $sub->from($table)->whereColumn('activity_id', 'id'),
            ))
            ->orderByDesc('id')
            ->chunkById($chunk, function ($activities) use ($sync, &$processed) {
                foreach ($activities as $activity) {
                    $sync($activity);
                    $processed++;
                }
            }, column: 'id');

        $this->info("Indexed {$processed} ".str('activity')->plural($processed).'.');

        return self::SUCCESS;
    }
}
