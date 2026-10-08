<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

/** Session locks survive chunk commits, and disappear when a killed process disconnects. */
final class BurstRebuildLock
{
    /** @var array<string, true> */
    private static array $held = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public function run(Connection $connection, string $table, Closure $run): mixed
    {
        $name = 'storyfeed:'.substr(hash('sha256', $connection->getDatabaseName().':'.$table), 0, 48);
        if (isset(self::$held[$name])) {
            throw new RuntimeException('A burst rebuild is already running.');
        }
        $driver = $connection->getDriverName();
        $file = null;
        $acquired = match ($driver) {
            'mysql', 'mariadb' => (int) $connection->selectOne('select get_lock(?, 0) as acquired', [$name], false)->acquired === 1,
            'pgsql' => (bool) $connection->selectOne('select pg_try_advisory_lock(18017, hashtext(?)) as acquired', [$name], false)->acquired,
            default => false,
        };
        if ($driver === 'sqlite') {
            $databases = $connection->select('pragma database_list', [], false);
            $database = collect($databases)->firstWhere('name', 'main')->file;
            // Use the actual file, independent of a caller's TMPDIR or path alias.
            $path = $database !== '' ? $database.'.storyfeed-bursts.lock'
                : sys_get_temp_dir().'/storyfeed-bursts-'.hash('sha256', $name).getmypid().'.lock';
            $file = fopen($path, 'c');
            $acquired = $file !== false && flock($file, LOCK_EX | LOCK_NB);
        }
        if (! $acquired) {
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('A burst rebuild is already running, or the database cannot acquire its maintenance lock.');
        }
        self::$held[$name] = true;
        $cacheSize = $driver === 'sqlite' ? (int) $connection->selectOne('pragma cache_size')->cache_size : null;
        try {
            // A bounded 64MiB pager avoids repeatedly reading the grouping
            // indexes under SQLite's 2MiB default; retain durability settings.
            if ($driver === 'sqlite') {
                $connection->statement('pragma cache_size = -65536');
            }

            return $run();
        } finally {
            if ($driver === 'sqlite') {
                $connection->statement('pragma cache_size = '.$cacheSize);
            }
            unset(self::$held[$name]);
            match ($driver) {
                'mysql', 'mariadb' => $connection->selectOne('select release_lock(?)', [$name], false),
                'pgsql' => $connection->selectOne('select pg_advisory_unlock(18017, hashtext(?))', [$name], false),
                default => null,
            };
            if (is_resource($file)) {
                flock($file, LOCK_UN);
                fclose($file);
            }
        }
    }
}
