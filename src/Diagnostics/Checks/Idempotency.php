<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * WARNING `idempotent.no_index`: a verb declares `idempotent()`, but the
 * activities table has no unique index of the app's own (core's primary key and `uid`
 * never repeat). Idempotency
 * only counts a duplicate INSERT as done, so without the app's own unique
 * index nothing is ever a duplicate and the declaration does nothing.
 */
class Idempotency extends Check
{
    public function name(): string
    {
        return 'idempotent';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $verbs = [];

        foreach ($storyfeed->storyDefinitions() as $definition) {
            if (($definition->queueing()['idempotent'] ?? false) === true) {
                foreach ($definition->objectTypes as $type) {
                    $verbs[] = "{$type}.{$definition->verb}";
                }
            }
        }

        if ($verbs === [] || ! $this->hasTable('activities')) {
            return;
        }

        foreach (Schema::getIndexes($this->table('activities')) as $index) {
            // Core's own: the primary key and the ULID every activity gets.
            if ($index['unique'] && ! $index['primary'] && $index['columns'] !== ['uid']) {
                return;
            }
        }

        $verbs = array_values(array_unique($verbs));

        yield Finding::warning(
            'idempotent.no_index',
            implode(', ', $verbs).' '.(count($verbs) === 1 ? 'is' : 'are').' declared idempotent(), but the '
            .$this->table('activities').' table has no unique index of your own (only the id and uid columns core adds), so no publish is ever '
            .'a duplicate and the declaration does nothing. Add a migration with the unique index that defines a '
            .'duplicate for your app, for example on the object and verb.',
            ['verbs' => implode(', ', $verbs)],
        );
    }
}
