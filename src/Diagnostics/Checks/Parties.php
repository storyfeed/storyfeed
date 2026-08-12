<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;

/**
 * Parties are created implicitly from strings, so a typo silently mints a new
 * one. Surfacing unused parties is how that gets caught.
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
                    foreach (['actor', 'object', 'target', 'context'] as $role) {
                        $query->orWhere(function ($q) use ($role, $alias, $row) {
                            $q->where("{$role}_type", $alias)->where("{$role}_id", $row->getKey());
                        });
                    }
                })
                ->count();

            $subject = ['name' => $row->name, 'key' => $row->key, 'activities' => $count];

            yield $count === 0
                ? Finding::warning(
                    'parties.unused',
                    "Party `{$row->name}` ({$row->key}) has no activities — likely a typo'd name.",
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
