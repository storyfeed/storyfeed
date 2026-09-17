<?php

namespace Storyfeed\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Which columns the package writes that were added after the tables were first
 * published — and therefore whether an app's schema is current enough to write.
 *
 * TWO CALLERS THAT MUST AGREE. The doctor reports a missing column as an error,
 * because a write touching it throws SQLSTATE[42S22] at runtime. The optimize
 * pass must decline for exactly the same reason, and the two answering
 * differently is how a deploy dies on a column the doctor already knew about.
 * So the map lives here and both read it.
 *
 * ORDER IS WHY THIS EXISTS AT ALL. Forge's deploy script — and every script
 * derived from it — runs `php artisan migrate` AFTER the caching step, so on
 * the deploy that introduces a column, `optimize` meets yesterday's schema and
 * today's code. That is not a misconfiguration to correct; it is the normal
 * shape of a deploy, and the pass has to survive it.
 */
class SchemaState
{
    /**
     * Table key => columns the package writes that a first-generation install
     * will not have. Add a column to a shipped table, add it here.
     *
     * @var array<string, list<string>>
     */
    public const array EXPECTED = [
        'snapshots' => ['shape', 'body'],
        'groupings' => ['winner'],
    ];

    public static function table(string $key): string
    {
        return config("storyfeed.tables.{$key}", "feed_{$key}");
    }

    /**
     * Does this table carry every column the package writes to it?
     *
     * A table that does not exist is NOT reported as behind: that is a
     * different condition with a different remedy, and `Tables` owns it.
     */
    public static function current(string $key): bool
    {
        $table = self::table($key);

        if (! Schema::hasTable($table)) {
            return true;
        }

        foreach (self::EXPECTED[$key] ?? [] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
