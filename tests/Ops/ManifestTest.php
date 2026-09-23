<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The compiled-story manifest. A cache is a new instance of the silent-drift
 * class that already cost this package a production outage, so the tests here
 * are mostly about the drift being DETECTABLE and about a broken compile
 * leaving nothing behind.
 */

afterEach(function () {
    app(StoryManifest::class)->delete();
});

it('produces registries identical to an uncached boot', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $uncached = [
        'grammar' => Storyfeed::registeredGrammar(),
        'aggregateGrammar' => Storyfeed::registeredAggregateGrammar(),
        'icons' => Storyfeed::registeredIcons(),
    ];

    $this->artisan('storyfeed:cache')->assertSuccessful();

    // A fresh manager, seeded from the manifest rather than compiling.
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    Storyfeed::stories([DeliveryWasConfirmed::class]);
    expect(app(StoryManifest::class)->apply(app(StoryfeedManager::class)))->toBeTrue();

    expect(Storyfeed::registeredGrammar())->toBe($uncached['grammar'])
        ->and(Storyfeed::registeredAggregateGrammar())->toBe($uncached['aggregateGrammar'])
        ->and(Storyfeed::registeredIcons())->toBe($uncached['icons']);
});

it('keeps the verb mapping usable across a var_export round-trip', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $this->artisan('storyfeed:cache')->assertSuccessful();

    // Enum cases cannot survive var_export, so they are stored as their string
    // values — which the registry accepts by design, because extension types
    // must round-trip as raw strings.
    $manifest = app(StoryManifest::class)->read();

    expect($manifest['verbs']['confirm'])->toBe('Update');

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    app(StoryManifest::class)->apply(app(StoryfeedManager::class));

    expect(Storyfeed::activityTypeValue('confirm'))->toBe('Update');
});

it('registers with optimize and optimize:clear', function () {
    expect(ServiceProvider::$optimizeCommands)->toContain('storyfeed:cache')
        ->and(ServiceProvider::$optimizeClearCommands)->toContain('storyfeed:clear');
});

it('removes the manifest on clear', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $this->artisan('storyfeed:cache')->assertSuccessful();
    expect(app(StoryManifest::class)->exists())->toBeTrue();

    $this->artisan('storyfeed:clear')->assertSuccessful();
    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('writes nothing when a story fails to compile', function () {
    Storyfeed::stories([
        // Composite with no parent grammar — a compile error.
        Verb::make('delivery.upload')
            ->headline(':actor uploaded :object')
            ->groups(Group::composite()->headline(':actor uploaded :objects')),
    ]);

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('nothing was cached')
        ->assertFailed();

    // A broken Story must not be able to leave a half-manifest that boots.
    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('says so plainly when there are no stories to cache', function () {
    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('nothing to cache')
        ->assertSuccessful();

    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('reports a stale manifest, naming what drifted', function () {
    Storyfeed::stories([
        Verb::make('delivery.confirm')->headline(':actor confirmed :object'),
    ]);

    $this->artisan('storyfeed:cache')->assertSuccessful();

    expect(Storyfeed::doctor(['manifest'])->has('manifest.stale'))->toBeFalse();

    // Edit the story — the exact thing a developer does and then forgets to
    // recompile. Deploys, migrations and tests all stay green.
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Storyfeed::stories([
        Verb::make('delivery.confirm')->headline(':actor CONFIRMED :object'),
    ]);

    $report = Storyfeed::doctor(['manifest']);

    expect($report->has('manifest.stale'))->toBeTrue()
        ->and($report->withCode('manifest.stale')->first()->message)
        ->toContain('grammar[delivery.confirm]')
        ->toContain('storyfeed:cache');
});

it('reports nothing when no manifest is cached', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // Never written implicitly, so a developer who has not opted in has
    // nothing stale to fight.
    expect(app(StoryManifest::class)->exists())->toBeFalse()
        ->and(Storyfeed::doctor(['manifest'])->all())->toBeEmpty();
});

it('flags a cached manifest whose source no longer compiles', function () {
    Storyfeed::stories([
        Verb::make('delivery.confirm')->headline(':actor confirmed :object'),
    ]);

    $this->artisan('storyfeed:cache')->assertSuccessful();

    // The nastiest state: the feed keeps serving cached output while the
    // stories on disk are broken, so nothing else would notice.
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Storyfeed::stories([
        Verb::make('delivery.confirm')
            ->headline(':actor confirmed :object')
            ->groups(Group::on('nonexistent')->headline(':actors confirmed')),
    ]);

    $report = Storyfeed::doctor(['manifest']);

    expect($report->has('manifest.uncompilable'))->toBeTrue()
        ->and($report->withCode('manifest.uncompilable')->first()->message)
        ->toContain('serving CACHED output');
});

it('ignores a truncated or foreign manifest rather than half-applying it', function () {
    $path = app(StoryManifest::class)->path();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, '<?php return ["grammar" => "not-an-array"];');

    expect(app(StoryManifest::class)->read())->toBeNull()
        ->and(app(StoryManifest::class)->apply(app(StoryfeedManager::class)))->toBeFalse();
});

/*
 * REGISTERING A COMMAND AND RESOLVING IT ARE DIFFERENT ASSERTIONS, and only
 * the first was ever made. `optimizes()` takes a NAME; `php artisan optimize`
 * runs each one through `callSilently()`, which resolves it with Symfony's
 * `find()`. A registration carrying an option therefore asks Symfony to find a
 * command called "storyfeed:rebuild --recent=1000", and every deploy that runs
 * optimize dies on it.
 *
 * That shipped on 2026-09-11 and broke the first consumer to update past it.
 *
 * This resolves each registered name the way the optimize command does, rather
 * than running `optimize` itself: that writes a config and route cache into the
 * testbench skeleton, which survives the process and breaks every later run —
 * the trap `BackfillTest` already documents from the other direction.
 */
it('registers optimize commands the console can actually resolve', function () {
    $console = $this->app->make(Kernel::class);
    $console->bootstrap();

    $registered = [
        ...ServiceProvider::$optimizeCommands,
        ...ServiceProvider::$optimizeClearCommands,
    ];

    expect($registered)->not->toBeEmpty();

    foreach ($registered as $command) {
        // Must be a registered command NAME. `callSilently()` looks the string
        // up as one, so anything that is not a key here — a name with an option
        // welded onto it, most of all — cannot be run by optimize.
        expect($console->all())->toHaveKey($command);
    }
});
