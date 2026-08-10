<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class StoryfeedServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('storyfeed')
            ->hasConfigFile()
            ->hasMigrations([
                'create_feed_activities_table',
                'create_feed_snapshots_table',
                'create_feed_groupings_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(StoryfeedManager::class);
        $this->app->alias(StoryfeedManager::class, 'storyfeed');
    }

    public function packageBooted(): void
    {
        Relation::morphMap(config('storyfeed.morph_map', []));
    }
}
