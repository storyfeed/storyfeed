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
    }
}
