<?php

namespace Storyfeed\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Concerns\TestDatabases;
use Orchestra\Testbench\TestCase as Orchestra;
use Storyfeed\StoryfeedServiceProvider;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Workbench\App\Models\Courier;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

class TestCase extends Orchestra
{
    use TestDatabases;

    /** @var array<string, true> */
    protected static array $provisionedWorkerDatabases = [];

    /** @var array<string, string> Schema fingerprints, by database name. */
    protected static array $builtSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock at midday, keeping TODAY's date.
        //
        // Axis keys are day-grained (`:d`), so any test that publishes at
        // `now()->subMinutes(...)` and asserts ONE group silently depends on the
        // wall clock: run it at 00:20 and a 25-minute spread straddles midnight,
        // producing two groups. That is a real property of the grouping model,
        // not a bug — but a test asserting children-capping should not be
        // asserting it accidentally, and CI running past midnight UTC should not
        // fail for it. Midday leaves ±12h of margin in both directions.
        $this->travelTo(now()->startOfDay()->addHours(12));

        Relation::enforceMorphMap([
            'user' => User::class,
            'courier' => Courier::class,
            'customer' => Customer::class,
            'delivery' => Delivery::class,
            'dish' => Dish::class,
            'photo' => Photo::class,
        ]);
    }

    protected function tearDown(): void
    {
        // Testbench destroys the app but retained test callbacks can keep its
        // PDO handles alive until garbage collection. Close them after all
        // teardown callbacks have run, so parallel workers do not exhaust the
        // real engine's connection limit.
        $connections = $this->app?->make('db')->getConnections() ?? [];
        try {
            parent::tearDown();
        } finally {
            foreach ($connections as $connection) {
                $connection->disconnect();
            }
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            StoryfeedServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // The suite runs on SQLite by default. To run it against a real
        // engine — the read path has MySQL- and PostgreSQL-specific
        // behaviour that SQLite cannot show, timestamp precision being one —
        // point it at one:
        //
        //   STORYFEED_TEST_DB=mysql STORYFEED_TEST_PORT=3306 \
        //   STORYFEED_TEST_DATABASE=storyfeed_test STORYFEED_TEST_USERNAME=root \
        //   STORYFEED_TEST_PASSWORD=secret vendor/bin/pest
        //
        // Every test then drops and recreates the schema (see
        // defineDatabaseMigrations), so give it a database of its own.
        if (($driver = env('STORYFEED_TEST_DB')) !== null) {
            config()->set('database.connections.testing', [
                'driver' => $driver,
                'host' => env('STORYFEED_TEST_HOST', '127.0.0.1'),
                'port' => env('STORYFEED_TEST_PORT', $driver === 'pgsql' ? 5432 : 3306),
                'database' => env('STORYFEED_TEST_DATABASE', 'storyfeed_test'),
                'username' => env('STORYFEED_TEST_USERNAME', $driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => env('STORYFEED_TEST_PASSWORD', ''),
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }

        // Free-form verbs are a guarantee of the package; the suite exercises
        // them deliberately. StrictVerbTest opts in explicitly.
        config()->set('storyfeed.verbs.strict', false);

        // Same reasoning for grammar: "activities are never hidden by the read
        // path" means an unauthored activity MUST still publish and degrade to
        // a null headline, and most of this suite exercises that guarantee.
        // StrictGrammarTest opts in explicitly.
        config()->set('storyfeed.grammar.strict', false);

        // Point surface discovery at the workbench app. Testbench's skeleton
        // app/ has no Feedable models, so without this the scanner would
        // correctly find nothing and every surface assertion would pass
        // vacuously — the failure mode those assertions exist to prevent.
        config()->set('storyfeed.discovery.paths', [__DIR__.'/../workbench/app']);

        // The workbench User is the app's User. Testbench's skeleton points
        // the auth provider at Illuminate\Foundation\Auth\User, which is not
        // Feedable — exactly the condition the `entities` check exists to
        // report, so left alone it would warn on every doctor run in the
        // suite. The workbench is the app under test; its User is the actor.
        config()->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Testbench invokes these stubs directly instead of RefreshDatabase,
        // so Laravel's automatic worker-database callback does not apply.
        // Reuse its provision/switch helpers before any schema is recreated.
        if (ParallelTesting::token() !== false && Schema::getConnection()->getDriverName() !== 'sqlite') {
            $rootDatabase = Schema::getConnection()->getDatabaseName();
            $database = $this->testDatabase($rootDatabase);
            if (! isset(self::$provisionedWorkerDatabases[$database])) {
                $this->ensureTestDatabaseExists($rootDatabase);
                self::$provisionedWorkerDatabases[$database] = true;
            }
            $this->switchToDatabase($database);
        }

        // A real engine keeps its tables between tests; SQLite's :memory: does
        // not. Recreating the schema for every test took the MySQL cell past
        // its CI timeout (#112), so while the schema still matches the one
        // built here, empty the tables that hold rows instead, as Laravel's
        // DatabaseTruncation does. A test that changes the schema (upgrade
        // fixtures, ad-hoc tables) gets it rebuilt for the next test.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $database = Schema::getConnection()->getDatabaseName();

            if ((self::$builtSchemas[$database] ?? null) === $this->schemaFingerprint()) {
                $this->truncateTablesWithRows();

                return;
            }

            Schema::dropAllTables();
        }

        // Published migrations are timestamped in order; the stubs are not,
        // so create_* stubs must run before any alter-style stub.

        $stubs = glob(__DIR__.'/../database/migrations/*.stub');

        usort($stubs, fn ($a, $b) => str_starts_with(basename($b), 'create_') <=> str_starts_with(basename($a), 'create_'));

        foreach ($stubs as $stub) {
            (include $stub)->up();
        }

        foreach (glob(__DIR__.'/../workbench/database/migrations/*.php') as $migration) {
            (include $migration)->up();
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            self::$builtSchemas[Schema::getConnection()->getDatabaseName()] = $this->schemaFingerprint();
        }
    }

    /**
     * Every column and index in the current database, hashed, so a schema a
     * test altered is never mistaken for the one defineDatabaseMigrations built.
     */
    protected function schemaFingerprint(): string
    {
        $connection = Schema::getConnection();

        $rows = $connection->getDriverName() === 'pgsql'
            ? $connection->select(<<<'SQL'
                select concat_ws(':', table_name, column_name, data_type, is_nullable, column_default) as line
                from information_schema.columns where table_schema = current_schema()
                union all
                select concat_ws(':', 'index', tablename, indexdef)
                from pg_indexes where schemaname = current_schema()
                SQL)
            : $connection->select(<<<'SQL'
                select concat_ws(':', table_name, column_name, column_type, is_nullable, column_default, extra) as line
                from information_schema.columns where table_schema = database()
                union all
                select concat_ws(':', 'index', table_name, index_name, seq_in_index, column_name, non_unique)
                from information_schema.statistics where table_schema = database()
                SQL);

        $lines = array_map(fn (object $row) => $row->line, $rows);
        sort($lines);

        return md5(implode("\n", $lines));
    }

    protected function truncateTablesWithRows(): void
    {
        $connection = Schema::getConnection();

        Schema::withoutForeignKeyConstraints(function () use ($connection) {
            foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
                $query = $connection->table($table['schema_qualified_name']);

                if ($query->exists()) {
                    $query->truncate();
                }
            }
        });
    }
}
