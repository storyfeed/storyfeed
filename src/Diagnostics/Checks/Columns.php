<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Columns added after 1.x tables were first published. A consumer whose
 * install predates the column deploys green, then every write that touches it
 * throws SQLSTATE[42S22] at runtime — a production feed once froze for hours
 * this way (snapshot writes dying silently while reads looked alive). This is
 * the mechanical detector for schema drift between published migrations and
 * what the package writes.
 *
 * Severity is Error, not Warning: unlike a missing headline, this one is
 * already breaking writes.
 */
class Columns extends Check
{
    /** @var array<string, list<string>> */
    protected const EXPECTED = [
        'snapshots' => ['shape'],
        'groupings' => ['winner'],
    ];

    public function name(): string
    {
        return 'columns';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach (self::EXPECTED as $key => $columns) {
            $table = $this->table($key);

            if (! Schema::hasTable($table)) {
                continue; // Tables already reported it
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    yield Finding::error(
                        'columns.missing',
                        "Table `{$table}` is missing the `{$column}` column — writes touching it will throw at "
                        .'runtime. Publish and run the package migrations (vendor:publish --tag=storyfeed-migrations).',
                        ['table' => $table, 'column' => $column],
                    );
                }
            }
        }
    }
}
