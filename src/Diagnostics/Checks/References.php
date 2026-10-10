<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SchemaState;

/**
 * App-reference columns still in their pre-0.13 shape. The package compares
 * them with its own keys cast to varchar(36); on PostgreSQL a bigint column
 * fails that comparison ("operator does not exist: character varying =
 * bigint"), so every read joining tombstones throws. An install upgrading
 * from 0.12 on PostgreSQL once saw nine checks fail that way, none of them
 * naming the cause.
 *
 * Severity is Error: on PostgreSQL this is already breaking reads.
 */
class References extends Check
{
    public function name(): string
    {
        return 'references';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach (SchemaState::misshapenReferences() as $table => $columns) {
            $list = implode(', ', array_map(fn (string $column) => "`{$column}`", $columns));

            yield Finding::error(
                'references.shape',
                "Table `{$table}` stores {$list} in the pre-0.13 shape, not varchar(36), and PostgreSQL can't "
                .'compare them with package keys. Publish and run the package migrations '
                .'(vendor:publish --tag=storyfeed-migrations) to convert them.',
                ['table' => $table, 'columns' => implode(',', $columns)],
            );
        }
    }
}
