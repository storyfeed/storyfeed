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
use Storyfeed\Support\StoryManifest;

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
                'create_feed_participants_table',
                'create_feed_parties_table',
                'create_feed_batches_table',
                'create_feed_meta_table',
                'add_shape_to_feed_snapshots_table',
            ])
            ->hasCommands([
                Console\RebuildCommand::class,
                Console\TrickleCommand::class,
                Console\PruneCommand::class,
                Console\CurateCommand::class,
                Console\CloseBatchesCommand::class,
                Console\BundleCommand::class,
                Console\ParticipantsCommand::class,
                Console\DoctorCommand::class,
                Console\VerbsCommand::class,
                Console\CacheCommand::class,
                Console\ClearCommand::class,
                Console\StoriesCommand::class,
                Console\StoryMakeCommand::class,
                Console\FeedMakeCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(StoryfeedManager::class);
        $this->app->alias(StoryfeedManager::class, 'storyfeed');
    }

    public function packageBooted(): void
    {
        // Stories compile AFTER every provider has booted, so provider
        // ordering is irrelevant: compilation validates group axes against the
        // axis registry and reads the verb registry, and an app that calls
        // stories() before axes() would otherwise get an "unknown axis" throw
        // for a perfectly correct configuration. The manager also compiles
        // lazily on first registry read, which covers console commands, tests
        // and the fake — anything reaching the registries outside a request.
        $this->app->booted(function () {
            $storyfeed = $this->app->make(StoryfeedManager::class);

            // A cached manifest short-circuits compilation. Applied HERE, not
            // during registration, because every provider's stories() calls
            // must already have landed.
            $this->app->make(StoryManifest::class)->apply($storyfeed);

            $storyfeed->compileStories();
        });

        // ONE listener, registered against the interface. Laravel's dispatcher
        // walks class_implements() for object events, so this fires for every
        // implementor and costs nothing for anything else — and it appears in
        // `php artisan event:list`, which is the greppability answer to whether
        // an attribute would have been better.
        Event::listen(Contracts\PublishesToFeed::class, Listeners\PublishFeedActivity::class);

        // Join `php artisan optimize` / `optimize:clear`. Available in both
        // supported Laravel lanes, so no method_exists guard — that would only
        // hide a real incompatibility.
        $this->optimizes(
            optimize: 'storyfeed:cache',
            clear: 'storyfeed:clear',
            key: 'storyfeed',
        );

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
            // A force-deleted composite parent releases its members back to
            // inference before curation re-decides anything.
            (new Actions\ReleaseComposite)($event->activity);

            if (config('storyfeed.grouping.curate', true)) {
                (new CurateCluster)->afterDelete($event->activity);
            }
        });
    }
}
