<?php

namespace Storyfeed\Storyfeed;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Storyfeed\Storyfeed\Commands\StoryfeedCommand;

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
            ->hasMigration('create_storyfeed_table')
            ->hasCommand(StoryfeedCommand::class);
    }
}
