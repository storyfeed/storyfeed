<?php

namespace Storyfeed\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Orchestra\Testbench\TestCase as Orchestra;
use Storyfeed\StoryfeedServiceProvider;

class TestCase extends Orchestra
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Storyfeed\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
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

        $migrations = [
            __DIR__ . '/../database/migrations/create_users_table.php.stub',
            __DIR__ . '/../database/migrations/create_testing_tables.php.stub',
            __DIR__ . '/../database/migrations/create_storyfeed_tables.php.stub',
        ];

        foreach ($migrations as $migration) {
            $migration = include $migration;
            $migration->up();
        }

        Relation::enforceMorphMap([
            'user' => 'Storyfeed\Prototype\Models\User',
            'customer' => 'Storyfeed\Prototype\Models\Customer',
            'delivery' => 'Storyfeed\Prototype\Models\Delivery',
        ]);
    }
}
