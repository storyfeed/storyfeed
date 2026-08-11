<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;

/**
 * Surfaces silent-fallback traps as explicit warnings: verbs without grammar
 * or icons, unmapped AS2.0 verbs/aliases, snapshot backlog, and table state.
 */
class DoctorCommand extends Command
{
    protected $signature = 'storyfeed:doctor';

    protected $description = 'Audit grammar/icon/mapping coverage and feed health';

    public function handle(StoryfeedManager $storyfeed): int
    {
        $issues = 0;

        $issues += $this->checkTables();
        $issues += $this->checkCoverage($storyfeed);
        $issues += $this->checkBacklog();
        $issues += $this->checkParties();

        if ($issues === 0) {
            $this->info('Storyfeed looks healthy.');
        } else {
            $this->warn("{$issues} finding(s) — see above.");
        }

        return self::SUCCESS;
    }

    protected function checkTables(): int
    {
        $issues = 0;

        foreach (config('storyfeed.tables', []) as $key => $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Table `{$table}` ({$key}) does not exist — run the migrations.");
                $issues++;
            }
        }

        return $issues;
    }

    protected function checkCoverage(StoryfeedManager $storyfeed): int
    {
        if (! Schema::hasTable(config('storyfeed.tables.activities', 'feed_activities'))) {
            return 0;
        }

        $issues = 0;

        $pairs = $this->activityQuery()
            ->distinct()
            ->get(['object_type as type', 'verb']);

        foreach ($pairs as $pair) {
            $label = ($pair->type ?? '(no object)').'.'.$pair->verb;

            if ($storyfeed->template($pair->type, $pair->verb) === null) {
                $this->warn("No grammar entry resolves for `{$label}` — headlines will be null.");
                $issues++;
            }

            if ($storyfeed->icon($pair->type, $pair->verb) === null) {
                $this->warn("No icon resolves for `{$label}`.");
                $issues++;
            }

            $type = $storyfeed->activityType($pair->verb);

            if ($type === null) {
                $this->line("Note: verb `{$pair->verb}` has no AS2.0 mapping — will serialize as base `Activity`.");
            }

            if ($type instanceof ActivityType && $type->isIntransitive() && $pair->type !== null) {
                $count = $this->activityQuery()
                    ->where('verb', $pair->verb)
                    ->where('object_type', $pair->type)
                    ->count();

                $this->warn(
                    "Verb `{$pair->verb}` maps to intransitive type {$type->value} but {$count} activities carry "
                    .'an object — these serialize as base `Activity`. Map the verb to a transitive type, or stop '
                    .'setting an object.'
                );
                $issues++;
            }
        }

        return $issues;
    }

    protected function checkBacklog(): int
    {
        if (! Schema::hasTable(config('storyfeed.tables.activities', 'feed_activities'))) {
            return 0;
        }

        $backlog = $this->activityQuery()
            ->where(function ($query) {
                foreach (['actor', 'object', 'target', 'context'] as $role) {
                    $query->orWhere(function ($q) use ($role) {
                        $q->whereNotNull("{$role}_type")->whereNull("cached_{$role}_id");
                    });
                }
            })
            ->count();

        if ($backlog > 0) {
            $this->warn("{$backlog} activities have uncached entities — schedule storyfeed:trickle (or run storyfeed:rebuild).");

            return 1;
        }

        return 0;
    }

    /**
     * Parties are created implicitly from strings, so a typo silently mints
     * a new one. Surfacing unused parties is how that gets caught.
     */
    protected function checkParties(): int
    {
        $table = config('storyfeed.tables.parties', 'feed_parties');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $party = config('storyfeed.models.party', Party::class);

        $parties = $party::query()->get();

        if ($parties->isEmpty()) {
            return 0;
        }

        $alias = (new $party)->getMorphClass();
        $issues = 0;

        foreach ($parties as $row) {
            $count = $this->activityQuery()
                ->where(function ($query) use ($alias, $row) {
                    foreach (['actor', 'object', 'target', 'context'] as $role) {
                        $query->orWhere(function ($q) use ($role, $alias, $row) {
                            $q->where("{$role}_type", $alias)->where("{$role}_id", $row->getKey());
                        });
                    }
                })
                ->count();

            if ($count === 0) {
                $this->warn("Party `{$row->name}` ({$row->key}) has no activities — likely a typo'd name.");
                $issues++;
            } else {
                $this->line("Party `{$row->name}` ({$row->key}): {$count} activities.");
            }
        }

        return $issues;
    }

    protected function activityQuery()
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
