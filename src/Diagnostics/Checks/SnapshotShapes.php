<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\ShapeSignature;

/**
 * Snapshots of one model type carrying different shape fingerprints, and what
 * that does and does not mean. Cheap data-only check (no model loading); the
 * trickle is the healer.
 *
 * A NULL FINGERPRINT IS ALWAYS STALE. Those rows predate the fingerprint
 * entirely, and the trickle rewrites them.
 *
 * SEVERAL FINGERPRINTS IS NOT, BY ITSELF, DRIFT. Shape is a property of a ROW:
 * {@see ShapeSignature} tags every scalar with its type, so
 * a key that is an int on most rows and null on a few yields two fingerprints
 * for one class with nothing deployed. Dropping the key instead of nulling it
 * yields two as well, one for the key-path set. A consumer measured THREE on
 * one class — width and height, width only, neither — every one of them
 * correct, and had no honest way to collapse them: coercing an unknown
 * dimension to zero is forbidden by the class that reads it, because a box of a
 * guessed shape shifts on load exactly like no box.
 *
 * So multiplicity alone would be a warning nobody could ever clear, and a
 * warning that cannot be cleared teaches its reader to skip the list it appears
 * in — which costs the next REAL drift the only place it was going to be seen.
 *
 * WHAT SEPARATES THEM IS WHETHER THE HEALER STILL HAS WORK. The trickle
 * compares each row against its own model, so after a converged pass every
 * remaining difference is legitimate. `MaintenanceHistory` already records that
 * — `reshaped` on the last run — and reading it costs one indexed query and no
 * model loading, which keeps this check what its name says it is.
 *
 * Drift therefore reports as a warning until the trickle has converged, and as
 * information afterwards. A later deploy that really does change a shape makes
 * the next trickle run report work again, and the warning returns by itself.
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

        /*
         * The last completed trickle pass, or null if one has never run. A pass
         * that reshaped nothing has compared every candidate against its own
         * model and left them, so what remains is not staleness.
         */
        $passes = MaintenanceHistory::recent('trickle');
        $last = $passes === [] ? null : $passes[array_key_last($passes)];
        $converged = $last !== null && ($last['reshaped'] ?? 1) === 0;

        $mixed = $snapshot::query()
            ->selectRaw('model_type, count(distinct shape) as shapes, sum(case when shape is null then 1 else 0 end) as unshaped')
            ->groupBy('model_type')
            ->havingRaw('count(distinct shape) > 1 or sum(case when shape is null then 1 else 0 end) > 0')
            ->get();

        foreach ($mixed as $row) {
            $subject = ['model_type' => $row->model_type, 'shapes' => (int) $row->shapes];

            // Never fingerprinted at all: stale without qualification, whatever
            // the healer last reported.
            if ((int) $row->unshaped > 0) {
                yield Finding::warning(
                    'shapes.mixed',
                    "Snapshots of `{$row->model_type}` carry no shape fingerprint — they predate it. "
                    .'storyfeed:trickle rewrites them (or run it now).',
                    $subject,
                );

                continue;
            }

            if (! $converged) {
                yield Finding::warning(
                    'shapes.mixed',
                    "Snapshots of `{$row->model_type}` carry {$row->shapes} shape fingerprints — some may predate "
                    .'the current toFeed() structure. storyfeed:trickle compares each against its own model and '
                    .'rewrites what is stale; run it, and what survives a converged pass is legitimate.',
                    $subject,
                );

                continue;
            }

            yield Finding::info(
                'shapes.mixed',
                "Snapshots of `{$row->model_type}` carry {$row->shapes} shape fingerprints and the last "
                .'storyfeed:trickle pass rewrote nothing, so every one of them is what that model produces '
                .'today — an optional key, present on some rows and absent or null on others. Nothing to do. '
                .'A deploy that really changes the shape will make the next pass report work, and this returns '
                .'to a warning.',
                $subject,
            );
        }
    }
}
