<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Storyfeed\Commands\StoryfeedCommand;

class StoryfeedServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('storyfeed')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations([
                'create_storyfeed_tables',
            ])
            ->hasCommand(StoryfeedCommand::class);
    }

    public function packageRegistered()
    {
        $this->app->bind('storyfeed', function () {
            return new Storyfeed();
        });

        Relation::morphMap(config('storyfeed.morphmap', []));
    }
}
