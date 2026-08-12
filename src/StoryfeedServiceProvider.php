<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
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
                'create_feed_batches_table',
            ])
            ->hasCommands([
                Console\RebuildCommand::class,
                Console\TrickleCommand::class,
                Console\PruneCommand::class,
                Console\CurateCommand::class,
                Console\CloseBatchesCommand::class,
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

        // Opt-in, read-only AS2.0 endpoints (docs/activity-streams.md).
        // Off by default: exposing a feed is an app decision, not a
        // package side effect.
        if (config('storyfeed.routes.enabled', false)) {
            $prefix = trim((string) config('storyfeed.routes.prefix', 'storyfeed'), '/');

            Route::middleware(config('storyfeed.routes.middleware', []))
                ->prefix($prefix)
                ->group(function () {
                    Route::get('activities/{uid}', [Http\ActivityStreamsController::class, 'activity']);
                    Route::get('feed', [Http\ActivityStreamsController::class, 'feed']);
                });
        }

        // Deleting is the one thing that shrinks a cluster, so it is the one
        // thing that can invalidate a winner. Everything else is monotone.
        Event::listen(ActivityDeleted::class, function (ActivityDeleted $event) {
            if (config('storyfeed.grouping.curate', true)) {
                (new CurateCluster)->afterDelete($event->activity);
            }
        });
    }
}
