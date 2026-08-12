<?php

namespace Storyfeed\Grouping;

use Storyfeed\Contracts\GroupingStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;

/**
 * Emits one candidate hash per REGISTERED axis (Storyfeed::axes()) — the
 * axis registry is the single source of truth for recipes, applicability,
 * eligibility and pinned tokens; this strategy is just the loop.
 *
 * The default registry, day-bucketed:
 *
 *  - repeat:  the same actor doing the same kind of thing
 *             ("Sally uploaded 12 photos")
 *  - actors:  many actors, same verb + target
 *             ("Bob, Sally, and 3 others uploaded files to Project X")
 *  - targets: same actor + verb across many targets
 *             ("Sally commented on 2 projects")
 *  - object:  the same actor acting on ONE object repeatedly
 *             ("Bob made 5 revisions to Aut Beatae.docx") — the only axis
 *             that pins object identity, which is what makes an :object
 *             token safe in its aggregate templates
 */
class MultiAxisStrategy implements GroupingStrategy
{
    public function hashes(Activity $activity): array
    {
        $hashes = [];

        foreach (app(StoryfeedManager::class)->registeredAxes() as $axis) {
            $hash = $axis->hashFor($activity);

            if ($hash !== null) {
                $hashes[$axis->name] = $hash;
            }
        }

        return $hashes;
    }
}
