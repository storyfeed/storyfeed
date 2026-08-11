<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Models\Party;

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
                'create_feed_parties_table',
                'add_winner_to_feed_groupings_table',
            ])
            ->hasCommands([
                Console\RebuildCommand::class,
                Console\TrickleCommand::class,
                Console\PruneCommand::class,
                Console\CurateCommand::class,
                Console\DoctorCommand::class,
                Console\VerbsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(StoryfeedManager::class);
        $this->app->alias(StoryfeedManager::class, 'storyfeed');
    }

    public function packageBooted(): void
    {
        // Package-owned aliases must be in Eloquent's map for morphTo() to
        // resolve them. enforceMorphMap() merges by default, so an app that
        // enforces its own map keeps these. MorphResolver additionally
        // resolves them independently, covering a non-merging replacement.
        Relation::morphMap([
            config('storyfeed.morph_alias', 'storyfeed.party') => config('storyfeed.models.party', Party::class),
        ]);

        Relation::morphMap(config('storyfeed.morph_map', []));

        // Deleting is the one thing that shrinks a cluster, so it is the one
        // thing that can invalidate a winner. Everything else is monotone.
        Event::listen(ActivityDeleted::class, function (ActivityDeleted $event) {
            if (config('storyfeed.grouping.curate', true)) {
                (new CurateCluster)->afterDelete($event->activity);
            }
        });
    }
}
