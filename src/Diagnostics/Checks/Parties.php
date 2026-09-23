<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\IgnoredParties;

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
 *
 * DECLARED PARTIES (`Storyfeed::parties()`, 2026-09-23). Once a list exists,
 * a name outside it is ignored in production, so what was ignored is a
 * Warning (IgnoredParties kept it; nothing else would show it), as is a
 * verb's fixed `->actor()` that isn't declared, which throws in development
 * and is ignored in production. With parties in use and no list, an Info
 * says any name can become one.
 */
class Parties extends Check
{
    public function name(): string
    {
        return 'parties';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        yield from $this->declarations($storyfeed);

        if (! $this->hasTable('parties')) {
            return;
        }

        $party = config('storyfeed.models.party', Party::class);

        $parties = $party::query()->get();

        if ($parties->isEmpty()) {
            return;
        }

        if ($storyfeed->declaredParties() === null) {
            yield Finding::info(
                'parties.undeclared_list',
                'Parties are in use and Storyfeed::parties() declares none, so any name a verb\'s ->actor() or '
                .'Storyfeed::as() is given becomes one, including a name taken from a request. Declare them: '
                ."Storyfeed::parties(['Stripe', 'Paddle']).",
                ['parties' => $parties->count()],
            );
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

    /**
     * What `Storyfeed::parties()` guards: names production ignored, and fixed
     * actors that aren't declared.
     *
     * @return iterable<Finding>
     */
    protected function declarations(StoryfeedManager $storyfeed): iterable
    {
        $declared = $storyfeed->declaredParties();

        if ($declared === null) {
            return;
        }

        $slugs = array_map(Str::slug(...), $declared);

        foreach ($storyfeed->storyActors() as $key => $name) {
            if (! in_array(Str::slug($name), $slugs, true)) {
                yield Finding::warning(
                    'parties.undeclared_actor',
                    "[{$key}] acts as the party `{$name}`, which Storyfeed::parties() does not declare: it throws in "
                    .'development and is ignored in production. Add it to the list.',
                    ['key' => $key, 'name' => $name],
                );
            }
        }

        if (! $this->hasTable('meta')) {
            return;
        }

        foreach (IgnoredParties::all() as $name) {
            if (in_array(Str::slug($name), $slugs, true)) {
                continue;
            }

            yield Finding::warning(
                'parties.ignored',
                "An actor named the party `{$name}`, which Storyfeed::parties() does not declare, so it was ignored "
                .'and the activity kept the actor it would otherwise have had. Declare it if it is real.',
                ['name' => $name],
            );
        }
    }
}
