<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Recorded actor/object repetition is a composition smell, not a defect.
 * Compare both morph columns without resolving entities, including missing
 * models. Other role pairs depend on story semantics; target/context equality
 * in particular is expected. Like actorless coverage, inspect live history.
 */
class ReflexiveRoles extends Check
{
    public function name(): string
    {
        return 'reflexive';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return; // Tables already reported it.
        }

        $verbs = $this->activities()->toBase()
            ->whereColumn('actor_type', 'object_type')
            ->whereColumn('actor_id', 'object_id')
            ->selectRaw('verb, count(*) as total')
            ->groupBy('verb')
            ->orderBy('verb')
            ->get();

        foreach ($verbs as $row) {
            $count = (int) $row->total;

            yield Finding::info(
                'reflexive.actor_object',
                "{$count} ".str('activity')->plural($count)." of verb `{$row->verb}` "
                .($count === 1 ? 'records' : 'record').' the same entity as actor and object. '
                .'Read the recorded roles back to check that they cohere; reflexive records can be legitimate.',
                ['verb' => $row->verb, 'count' => $count],
            );
        }
    }
}
