<?php

namespace Storyfeed\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Orchestra\Testbench\TestCase as Orchestra;
use Storyfeed\StoryfeedServiceProvider;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::enforceMorphMap([
            'user' => User::class,
            'customer' => Customer::class,
            'delivery' => Delivery::class,
        ]);
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

        // Free-form verbs are a guarantee of the package; the suite exercises
        // them deliberately. StrictVerbTest opts in explicitly.
        config()->set('storyfeed.verbs.strict', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (glob(__DIR__.'/../database/migrations/*.stub') as $stub) {
            (include $stub)->up();
        }

        foreach (glob(__DIR__.'/../workbench/database/migrations/*.php') as $migration) {
            (include $migration)->up();
        }
    }
}
