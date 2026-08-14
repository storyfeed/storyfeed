<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Activities missing from the participants index that `involving()` reads.
 *
 * The failure this exists for is an upgrade, not a bug: publish-time sync
 * covers everything recorded since the table existed, so an install that
 * upgraded into it has correct NEW history and silently empty OLD history. The
 * feed looks fine; only an entity page looks oddly short.
 *
 * Warning, not error: nothing is broken, and the backfill is one command.
 */
class Participants extends Check
{
    public function name(): string
    {
        return 'participants';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $activities = $this->table('activities');
        $participants = SyncParticipants::table();

        if (! Schema::hasTable($activities) || ! Schema::hasTable($participants)) {
            return; // Tables already reported it
        }

        // An activity owes a participant row for every role it fills. Count
        // the ones that fill at least one and have none.
        $unindexed = DB::table($activities)
            ->where(function ($query) {
                foreach (SyncParticipants::ROLES as $role) {
                    $query->orWhereNotNull("{$role}_type");
                }
            })
            ->whereNotExists(fn ($sub) => $sub
                ->from($participants)
                ->whereColumn('activity_id', "{$activities}.id"))
            ->count();

        if ($unindexed > 0) {
            yield Finding::warning(
                'participants.unindexed',
                "{$unindexed} ".str('activity')->plural($unindexed).' missing from the participants index — '
                .'`involving()` will not find them. Run `php artisan storyfeed:participants` once to backfill.',
                ['unindexed' => $unindexed],
            );
        }
    }
}
