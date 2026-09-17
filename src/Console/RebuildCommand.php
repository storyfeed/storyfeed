<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\RebuildSnapshots;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\SchemaState;

/**
 * Recompile snapshots — exhaustively, or bounded to the newest activities.
 *
 * `toFeed()` output is CACHED. Changing that method changes what new snapshots
 * store and leaves every row already written saying what it said before. So a
 * consumer edits, deploys, looks, and sees nothing change, with nothing
 * anywhere telling them why. It cost two sessions an hour, and the owner —
 * who wrote the machinery — waited for a fix that had already shipped.
 *
 * `--recent` is the answer, and it runs on `php artisan optimize` beside the
 * manifest compile. A deploy has build steps; things are correct when it
 * finishes. That model needs no new concept and no explanation of why a row has
 * not caught up.
 */
class RebuildCommand extends Command
{
    /**
     * The default window, in ACTIVITIES scanned rather than entities found.
     *
     * A number an operator can reason about: "the last thousand activities" is
     * a sentence a person understands, and the entities behind it are however
     * many they are. A time budget would be neither — it varies by machine and
     * cannot be reasoned about before it runs.
     */
    public const int RECENT = 1000;

    protected $signature = 'storyfeed:rebuild
        {--recent= : Bound the pass to entities named by this many of the newest activities}';

    protected $description = 'Recompile entity snapshots and backfill cached links';

    public function handle(): int
    {
        $recent = $this->option('recent');
        $recent = $recent === null ? null : (int) $recent;

        /*
         * A DEPLOY MAY HAVE NO DATABASE. `php artisan optimize` is routinely
         * run on a build machine that has the code and not the connection, so a
         * command joined to it must decline rather than fail — a deploy broken
         * by a cache warmer is a worse bug than a stale snapshot.
         *
         * The same posture the doctor checks take: ask the schema, and say
         * nothing if the answer is no.
         */
        if (! $this->reachable()) {
            return self::SUCCESS;
        }

        $result = (new RebuildSnapshots)($recent);

        if ($recent === null) {
            $this->info("Snapshotted {$result['snapshotted']} entities ({$result['missing']} missing).");

            return self::SUCCESS;
        }

        $this->report($result['snapshotted'], $recent);

        return self::SUCCESS;
    }

    /**
     * What the deploy says, and it says nothing when there is nothing to say.
     *
     * THE MAJORITY CASE IS A NO-OP, which is what makes a line affordable at
     * all: most deploys change no `toFeed()`, the pass rewrites nothing, and a
     * consumer is not asked to read anything. When it does speak there is a
     * reason, and the reader is glad they did not have to know a command
     * existed.
     *
     * AND IT DOES NOT PROMISE WHAT IT CANNOT KEEP. "the rest follow with the
     * trickle" is a sentence about a command the consumer schedules themselves.
     * Printed at a deploy it would be believed, and for an app that never wired
     * the scheduler it would be false — the same shape as a warning nobody can
     * clear, pointed forward instead of back. `MaintenanceHistory` already
     * knows whether a pass has ever run, so the line is honest for the cost of
     * a query it can already make.
     */
    private function report(int $snapshotted, int $window): void
    {
        if ($snapshotted === 0) {
            return;
        }

        $this->info("Compiled {$snapshotted} snapshots from the last {$window} activities.");

        if (MaintenanceHistory::recent('trickle') === []) {
            $this->warn(
                'Older snapshots are not scheduled to follow — add storyfeed:trickle '
                .'to your scheduler, or run storyfeed:rebuild to compile them all now.'
            );

            return;
        }

        $this->line('  Older ones follow with the trickle.');
    }

    /**
     * Is there a database, and is its schema current enough to write to?
     *
     * BOTH HALVES ARE ABOUT DEPLOY ORDER. A build machine has the code and no
     * connection. A Forge deploy — where `migrate` runs AFTER the caching step
     * — has a connection and yesterday's schema, so on the deploy that adds a
     * column this pass meets a table it cannot write. It threw
     * SQLSTATE[42S22] and failed the deploy on 2026-09-17, against a column
     * `Diagnostics\Checks\Columns` already knew to look for.
     *
     * Declining is quiet on purpose. The condition clears the moment
     * `migrate` runs, the doctor reports it as an error for as long as it is
     * true, and a warning printed here would fire on every schema-changing
     * deploy pointing at nothing the operator should do.
     */
    private function reachable(): bool
    {
        try {
            if (! Schema::hasTable(config('storyfeed.tables.activities', 'feed_activities'))) {
                return false;
            }

            return SchemaState::current('snapshots');
        } catch (\Throwable) {
            return false;
        }
    }
}
