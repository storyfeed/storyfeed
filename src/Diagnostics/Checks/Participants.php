<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Activities missing from the participants index that `involving()` reads,
 * and rows whose copy of published_at no longer matches their activity's.
 *
 * The failure this exists for is an upgrade, not a bug: publish-time sync
 * covers everything recorded since the table existed, so an install that
 * upgraded into it has correct NEW history and silently empty OLD history. The
 * feed looks fine; only an entity page looks oddly short.
 *
 * Warning, not error: the feed itself renders every row correctly, doctor
 * cannot see whether any surface calls `involving()`, and the backfill is one
 * command.
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

        // An activity owes a participant row for every role it fills with an
        // id. Count the ones that fill at least one and have none. A role
        // with no model and no id behind it is in no index by design.
        $unindexed = DB::table($activities)
            ->where(function ($query) {
                foreach (SyncParticipants::ROLES as $role) {
                    $query->orWhere(fn ($q) => $q->whereNotNull("{$role}_type")->whereNotNull("{$role}_id"));
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

        // involving() orders by the rows' copy of published_at. Activity
        // re-stamps them when it is saved; a mass update bypasses that.
        $drifted = DB::table($participants)
            ->join($activities, "{$activities}.id", '=', "{$participants}.activity_id")
            ->where(fn ($query) => $query
                // SQLite stores text: `12:00:00` and `12:00:00.000000` are one instant.
                ->when(
                    DB::getDriverName() === 'sqlite',
                    fn ($q) => $q->whereRaw('julianday('.DB::getQueryGrammar()->wrap("{$participants}.published_at").') <> julianday('.DB::getQueryGrammar()->wrap("{$activities}.published_at").')'),
                    fn ($q) => $q->whereColumn("{$participants}.published_at", '<>', "{$activities}.published_at"),
                )
                ->orWhere(fn ($q) => $q->whereNull("{$participants}.published_at")->whereNotNull("{$activities}.published_at"))
                ->orWhere(fn ($q) => $q->whereNotNull("{$participants}.published_at")->whereNull("{$activities}.published_at")))
            ->distinct()
            ->count("{$participants}.activity_id");

        if ($drifted > 0) {
            yield Finding::warning(
                'participants.drift',
                "{$drifted} ".str('activity')->plural($drifted).' moved in time without their participants index — '
                .'`involving()` lists them at their old time. Run `php artisan storyfeed:participants` to rewrite the index.',
                ['drifted' => $drifted],
            );
        }
    }
}
