<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
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
        $issues += $this->checkAggregateCoverage($storyfeed);
        $issues += $this->checkAggregateTokens($storyfeed);
        $issues += $this->checkHashLengths();
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

    /**
     * An aggregate template referencing a token its axis does not pin
     * renders a lie: ":object" on the repeat axis produced "made 5
     * revisions to Aut Beatae.docx" over children spanning five different
     * documents. Registration accepts anything; this is where it's caught.
     */
    protected function checkAggregateTokens(StoryfeedManager $storyfeed): int
    {
        $issues = 0;

        foreach ($storyfeed->registeredAggregateGrammar() as $key => $template) {
            if (! is_string($template)) {
                continue; // closures pre-render; nothing to inspect
            }

            $axis = explode('.', $key, 2)[0];

            // Derived from the axis registry — a token is allowed iff the
            // axis's recipe makes it homogeneous; wildcards get the
            // intersection across all registered axes.
            $allowed = $storyfeed->aggregateTokens($axis);

            if ($allowed === null) {
                $this->line(
                    "Note: aggregate grammar key `{$key}` references axis `{$axis}`, which is not registered — "
                    .'it will never resolve. Registered axes: '.implode(', ', array_keys($storyfeed->registeredAxes())).'.'
                );

                continue;
            }

            preg_match_all('/:[a-z]+/', $template, $matches);

            foreach (array_diff(array_unique($matches[0]), $allowed) as $token) {
                $this->warn(
                    "Aggregate template `{$key}` references `{$token}`, which "
                    .($axis === '*' ? 'not every axis pins' : "the {$axis} axis does not pin")
                    .' — groups on that axis may span many values, so the headline can lie. '
                    .'Allowed here: '.implode(' ', $allowed).'.'
                );
                $issues++;
            }
        }

        return $issues;
    }

    /**
     * A grouping hash at the column limit has probably been truncated —
     * and truncated hashes OVER-group: unrelated activities collapse into
     * one node. (Learned the hard way: a legacy app stored hashes in
     * VARCHAR(50) with no guard.)
     */
    protected function checkHashLengths(): int
    {
        $groupings = config('storyfeed.tables.groupings', 'feed_groupings');

        if (! Schema::hasTable($groupings)) {
            return 0;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $suspect = $grouping::query()
            ->whereRaw($this->lengthExpression('hash').' >= 255')
            ->count();

        if ($suspect > 0) {
            $this->warn(
                "{$suspect} grouping hash(es) are at the 255-character column limit — likely truncated, which "
                .'silently over-groups unrelated activities. Shorten the strategy output (e.g. digest long parts).'
            );

            return 1;
        }

        return 0;
    }

    protected function lengthExpression(string $column): string
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlsrv' => "len({$column})",
            default => "length({$column})",
        };
    }

    /**
     * A group on an aggregate axis without aggregate grammar renders the
     * singular headline of its head member — "Sally uploaded a file" over a
     * node that carries three actors. Silent, and wrong.
     */
    protected function checkAggregateCoverage(StoryfeedManager $storyfeed): int
    {
        $groupings = config('storyfeed.tables.groupings', 'feed_groupings');
        $activities = config('storyfeed.tables.activities', 'feed_activities');

        if (! Schema::hasTable($groupings) || ! Schema::hasTable($activities)) {
            return 0;
        }

        $issues = 0;

        $pairs = $this->activityQuery()
            ->join($groupings, "{$groupings}.activity_id", '=', "{$activities}.id")
            ->where("{$groupings}.winner", true)
            ->whereIn("{$groupings}.bucket", $storyfeed->aggregateAxes())
            ->distinct()
            ->get(["{$groupings}.bucket as axis", "{$activities}.verb"]);

        foreach ($pairs as $pair) {
            if ($storyfeed->aggregateTemplate($pair->axis, $pair->verb) === null) {
                $this->warn(
                    "No aggregate grammar resolves for `{$pair->axis}.{$pair->verb}` — those group nodes fall back "
                    .'to the singular headline only when its tokens are safe for the axis, and otherwise render '
                    .'with NO headline at all. Register one with Storyfeed::aggregateGrammar().'
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

        $backlog = $this->activityQuery()->uncached()->count();

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
