<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;

/**
 * Parties are created implicitly from strings, so a typo silently mints a new
 * one. Surfacing unused parties is how that gets caught.
 *
 * Both lines are Info. A party with no activities appears on no surface, so
 * it is the same shape as a declared verb nobody has recorded — and the typo
 * it hints at is read from the PAIR of lines (the right name at zero beside
 * the wrong one at N), both of which are still printed. It was a Warning
 * until 2026-09-10, which tripped `--fail-on=warning` on a party that was
 * simply seeded ahead of traffic.
 */
class Parties extends Check
{
    public function name(): string
    {
        return 'parties';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('parties')) {
            return;
        }

        $party = config('storyfeed.models.party', Party::class);

        $parties = $party::query()->get();

        if ($parties->isEmpty()) {
            return;
        }

        $alias = (new $party)->getMorphClass();

        foreach ($parties as $row) {
            $count = $this->activities()
                ->where(function ($query) use ($alias, $row) {
                    foreach (ActivityRoles::STORED as $role) {
                        $query->orWhere(function ($q) use ($role, $alias, $row) {
                            $q->where("{$role}_type", $alias)->where("{$role}_id", $row->getKey());
                        });
                    }
                })
                ->count();

            $subject = ['name' => $row->name, 'key' => $row->key, 'activities' => $count];

            yield $count === 0
                ? Finding::info(
                    'parties.unused',
                    "Party `{$row->name}` ({$row->key}) has no activities — a typo'd name, or one seeded ahead of traffic.",
                    $subject,
                )
                : Finding::info(
                    'parties.used',
                    "Party `{$row->name}` ({$row->key}): {$count} activities.",
                    $subject,
                );
        }
    }
}
