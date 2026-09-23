<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MorphResolver;

/**
 * A Feedable subclass whose rows are deleted through a parent that is not
 * Feedable — `FeedablePhoto extends Media implements Feedable`, recorded as
 * an `object_type`. The app deletes a `Media`, so Eloquent fires the
 * parent's events and never the subclass's own.
 *
 * INFO, NOT A WARNING. It is a legitimate design, used on purpose so the
 * stored type resolves to something Feedable over a vendor's table. The
 * finding names the arrangement and says how deletions are heard: through
 * the parent's events, when Feedables::listenThroughParents() picked the
 * class up (it is in the morph map, or registered with
 * `Storyfeed::feedable()`); otherwise only by the trickle, on its schedule.
 * Updates are never heard through the parent: probing on every save to the
 * parent's table would cost more than a stale snapshot, so a subclass
 * updated as its parent keeps its snapshot until the trickle runs.
 *
 * A subclass over a table of its own shares no rows with its parent, so it
 * is not reported.
 */
class InheritedDeletes extends Check
{
    public function name(): string
    {
        return 'inherited';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return; // Tables already reported it.
        }

        $feedables = app(Feedables::class);

        foreach ($this->recordedAliases('object') as $alias) {
            $class = MorphResolver::classFor($alias);

            if ($class === null || ! is_a($class, Model::class, true) || ! $feedables->isFeedable($class)) {
                continue;
            }

            $table = (new $class)->getTable();
            $parents = array_values(array_filter(
                $feedables->nonFeedableParents($class),
                fn (string $parent) => (new $parent)->getTable() === $table,
            ));

            if ($parents === []) {
                continue;
            }

            $listening = $feedables->listensThroughParents($class);
            $through = implode(', ', $parents);

            yield Finding::info(
                'inherited.parent_deletes',
                "`{$alias}` ({$class}) is deleted through {$through}, which is not Feedable, so its own model events never fire. "
                .($listening
                    ? 'Storyfeed listens to the parent\'s delete, force-delete and restore events for it, so its tombstones arrive at deletion time; the trickle still sweeps as a safety net.'
                    : 'Nothing listens to the parent for it, so its tombstones arrive on the trickle\'s schedule. Add it to the morph map to hear deletions as they happen.')
                ." Updates are not heard through {$through} either way: a {$class} updated as its parent keeps its snapshot until the trickle runs.",
                ['alias' => $alias, 'class' => $class, 'parents' => $through, 'listening' => $listening],
            );
        }
    }
}
