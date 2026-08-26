<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\TrickleSnapshots;

class TrickleCommand extends Command
{
    protected $signature = 'storyfeed:trickle
        {--limit= : Maximum activities to process this run}
        {--prune : DELETE activities whose feed roles cannot be resolved}';

    protected $description = 'Snapshot uncached feed activities (newest first) and report unresolvable ones';

    public function handle(): int
    {
        $limit = $this->option('limit');

        // Null, not false: the flag turns pruning ON, and its absence defers to
        // config rather than overriding an app that switched pruning on there.
        $prune = $this->option('prune') ? true : null;

        $result = (new TrickleSnapshots)($limit === null ? null : (int) $limit, $prune);

        $this->info(
            "Snapshotted {$result['snapshotted']} activities, pruned {$result['pruned']} orphans, "
            ."reshaped {$result['reshaped']} stale snapshots."
        );

        if ($result['unresolved'] > 0) {
            // A WARNING, not a tally. These rows are still in the feed and still
            // being read; what they are missing is a `Feedable` on the model
            // behind one of their roles. Naming the fix is the point — an app
            // that reads this as "junk to clear out" reaches for --prune, which
            // is the one response that removes the evidence instead.
            // Two short lines rather than one long one: a console that wraps or
            // truncates a paragraph can drop the half that names the fix, and
            // the half that names the fix is the reason for printing anything.
            $this->warn("{$result['unresolved']} activities have a role that cannot be resolved.");
            $this->warn('Still shown, with a placeholder. Usually a model missing Feedable.');
            $this->line('Run storyfeed:doctor for detail, or --prune to delete them.');
        }

        return self::SUCCESS;
    }
}
