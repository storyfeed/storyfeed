<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\StoryfeedManager;

/**
 * Role constraints (`->whereActor()`, `->whereRole()`) held against the
 * rows already stored. A publish checks them as it happens; rows stored
 * before a constraint was declared, or written without publishing, never
 * were.
 *
 *  - WARNING `role_constraints.violated`: live rows whose role is a type the
 *    verb's constraint doesn't allow. They stay in the feed, as every row
 *    does; the next publish like them would throw.
 *
 * An empty role is never a violation (an anonymous actor is unknown, not
 * the wrong type), and neither is a tombstone: the model was the right type
 * when it was stored.
 */
class RoleConstraints extends Check
{
    public function name(): string
    {
        return 'role_constraints';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        try {
            $constraints = $storyfeed->storyWheres();
        } catch (StoryMisconfigured) {
            return;
        }

        if ($constraints === []) {
            return;
        }

        $tombstone = (new (config('storyfeed.models.tombstone', FeedTombstone::class)))->getMorphClass();
        $verbs = array_values(array_unique(array_map(fn (string $key) => substr($key, (int) strrpos($key, '.') + 1), array_keys($constraints))));
        $roles = array_values(array_unique(array_merge(...array_map(array_keys(...), array_values($constraints)))));

        /** @var array<string, array{type: string|null, verb: string, role: string, count: int, given: list<string>, expected: list<string>}> $violations */
        $violations = [];

        foreach ($roles as $role) {
            $rows = $this->activities()->toBase()
                ->whereNotNull("{$role}_type")
                ->when(! in_array('*', $verbs, true), fn ($query) => $query->whereIn('verb', $verbs))
                ->select('object_type', 'verb', "{$role}_type as role_type")->selectRaw('count(*) as aggregate')
                ->groupBy('object_type', 'verb', "{$role}_type")
                ->orderBy('verb')->orderBy('object_type')
                ->get();

            foreach ($rows as $row) {
                $type = $row->object_type === null || $row->object_type === $tombstone ? null : (string) $row->object_type;
                $verb = (string) $row->verb;
                $given = (string) $row->role_type;
                $allowed = $storyfeed->wheres($type, $verb)[$role] ?? null;

                if ($allowed === null || $given === $tombstone || in_array($given, $allowed, true)) {
                    continue;
                }

                $key = ($type ?? '*').".{$verb}:{$role}";
                $violations[$key] ??= ['type' => $type, 'verb' => $verb, 'role' => $role, 'count' => 0, 'given' => [], 'expected' => $allowed];
                $violations[$key]['count'] += (int) $row->aggregate;
                $violations[$key]['given'][] = $given;
            }
        }

        foreach ($violations as $violation) {
            $on = $violation['type'] === null ? "`{$violation['verb']}`" : "`{$violation['type']}.{$violation['verb']}`";
            $count = $violation['count'];

            yield Finding::warning(
                'role_constraints.violated',
                "{$on} allows only ".implode(', ', $violation['expected'])." as its {$violation['role']}, but "
                .number_format($count).' live '.($count === 1 ? 'row has' : 'rows have').' '
                .implode(', ', array_unique($violation['given'])).'. They were stored before the constraint, or '
                .'written without publishing; they stay in the feed, and the next publish like them throws. '
                .'Widen the constraint, or correct the rows.',
                [
                    'type' => $violation['type'],
                    'verb' => $violation['verb'],
                    'role' => $violation['role'],
                    'expected' => implode(', ', $violation['expected']),
                    'given' => implode(', ', array_unique($violation['given'])),
                    'rows' => $count,
                ],
            );
        }
    }
}
