<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
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
 * partial run leaves the most-read history correct.
 */
class ParticipantsCommand extends Command
{
    protected $signature = 'storyfeed:participants
        {--chunk=500 : Activities to process per batch}
        {--missing : Only activities that have no participant rows yet}';

    protected $description = 'Rebuild the participants index used by involving() (backfill)';

    public function handle(): int
    {
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
