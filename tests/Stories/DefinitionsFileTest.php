<?php

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\WithConfig;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\StoryfeedServiceProvider;
use Storyfeed\Tests\TestCase;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * routes/feed.php: loaded in booted() like routes/channels.php, and given
 * route:cache semantics by storyfeed:cache. Each test writes its own file
 * and reboots the app pointed at it, because loading is a boot decision.
 */

/** Write a definitions file and return its path. */
function writeDefinitions(string $body): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-definitions-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, "<?php\n\nuse Storyfeed\\Facades\\Story;\nuse Storyfeed\\Facades\\Storyfeed;\nuse Workbench\\App\\Models\\Delivery;\n\n".$body."\n");

    $GLOBALS['storyfeedDefinitionsFiles'][] = $path;

    return $path;
}

/** Reboot the app with storyfeed.definitions pointing at $path. */
function bootWithDefinitions(TestCase $test, string|false $path): void
{
    $test::usesTestingFeature(new WithConfig('storyfeed.definitions', $path));

    (fn () => $this->refreshApplication())->call($test);
}

afterEach(function () {
    app(StoryManifest::class)->delete();

    foreach ($GLOBALS['storyfeedDefinitionsFiles'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['storyfeedDefinitionsFiles'] = [];
});

it('loads the definitions file after every provider has booted', function () {
    bootWithDefinitions($this, writeDefinitions(<<<'PHP'
        Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object')->icon('truck');
        PHP));

    expect(app(DefinitionsFile::class)->isLoaded())->toBeTrue()
        ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck');
});

it('names the file and line a definition was written on', function () {
    $path = writeDefinitions(<<<'PHP'
        Story::verb('ship')->headline(':actor shipped :object');
        PHP);

    bootWithDefinitions($this, $path);

    $definition = collect(Storyfeed::storyDefinitions())->firstWhere('verb', 'ship');

    // Line 7: the four-line header, a blank line, then the heredoc's line.
    expect($definition->source)->toEndWith(basename($path).':7');
});

it('loads nothing when turned off or when the file is missing', function () {
    bootWithDefinitions($this, false);

    expect(app(DefinitionsFile::class)->path())->toBeNull()
        ->and(Storyfeed::storyDefinitions())->toBe([]);

    bootWithDefinitions($this, sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-no-such-file.php');

    expect(Storyfeed::storyDefinitions())->toBe([]);
});

it('skips the file at boot once cached, serving the manifest instead', function () {
    $path = writeDefinitions(<<<'PHP'
        Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object');
        Story::for(Delivery::class)->verb('rush')->headline(fn () => ':actor rushed :object');
        PHP);

    bootWithDefinitions($this, $path);

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('is no longer loaded at boot')
        ->assertSuccessful();

    bootWithDefinitions($this, $path);

    // Not required, as a route file isn't after route:cache.
    expect(app(DefinitionsFile::class)->isLoaded())->toBeFalse()
        ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        // The closure came back from the manifest, serialised as closure routes are.
        ->and(Storyfeed::template('delivery', 'rush'))->toBeInstanceOf(Closure::class)
        ->and(app(DefinitionsFile::class)->isLoaded())->toBeFalse();
});

it('reports a stale manifest when the file changes after caching', function () {
    $path = writeDefinitions(<<<'PHP'
        Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object');
        Story::for(Delivery::class)->verb('rush')->headline(fn () => 'rushed');
        PHP);

    bootWithDefinitions($this, $path);
    $this->artisan('storyfeed:cache')->assertSuccessful();
    bootWithDefinitions($this, $path);

    expect(Storyfeed::doctor(['manifest'])->has('manifest.stale'))->toBeFalse();

    // Edit both lines, then run the doctor without recaching. Written to a
    // new path only because SerializableClosure caches each file's source
    // per process, and a real doctor run is a fresh one.
    bootWithDefinitions($this, writeDefinitions(<<<'PHP'
        Story::for(Delivery::class)->verb('ship')->headline(':actor SHIPPED :object');
        Story::for(Delivery::class)->verb('rush')->headline(fn () => 'RUSHED');
        PHP));

    $finding = Storyfeed::doctor(['manifest'])->withCode('manifest.stale')->first();

    expect($finding)->not->toBeNull()
        ->and($finding->message)->toContain('grammar[delivery.ship]')
        ->and($finding->message)->toContain('grammar[delivery.rush]');
});

it('knows a Story class registered in the file once cached', function () {
    $path = writeDefinitions(<<<'PHP'
        Storyfeed::stories([Workbench\App\Stories\DeliveryWasConfirmed::class]);
        PHP);

    bootWithDefinitions($this, $path);
    $this->artisan('storyfeed:cache')->assertSuccessful();
    bootWithDefinitions($this, $path);

    expect(app(DefinitionsFile::class)->isLoaded())->toBeFalse()
        ->and(Storyfeed::hasStory(DeliveryWasConfirmed::class))->toBeTrue()
        ->and(Storyfeed::template('delivery', 'confirm'))->not->toBeNull();
});

it('refuses to cache a file that calls a hand-written registry', function () {
    $path = writeDefinitions(<<<'PHP'
        Story::verb('ship')->headline(':actor shipped :object');
        Storyfeed::icons(['*.ship' => 'truck']);
        PHP);

    bootWithDefinitions($this, $path);

    // It works uncached; it would silently stop working cached.
    expect(Storyfeed::icon(null, 'ship'))->toBe('truck');

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('Storyfeed::icons()')
        ->assertFailed();

    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('fails on a closure that cannot be serialised, naming its line', function () {
    $path = writeDefinitions(<<<'PHP'
        $steps = (function () { yield 1; })();
        Story::verb('ship')->headline(fn () => $steps->current() ? 'shipped' : 'held');
        PHP);

    bootWithDefinitions($this, $path);

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('nothing was cached')
        ->expectsOutputToContain(basename($path).':8')
        ->assertFailed();

    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('creates the file on install from the stub, and never overwrites one', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-install-'.bin2hex(random_bytes(6)).'.php';
    $GLOBALS['storyfeedDefinitionsFiles'][] = $path;
    config()->set('storyfeed.definitions', $path);

    $published = config_path('storyfeed.php');
    $hadConfig = file_exists($published);

    try {
        $this->artisan('storyfeed:install', ['--without-migrations' => true])
            ->expectsOutputToContain('Created')
            ->assertSuccessful();

        $stub = (string) file_get_contents($path);

        expect($stub)->toContain('use Storyfeed\Facades\Story;')
            // No live code: every definition is commented out.
            ->and(preg_match('/^Story::/m', $stub))->toBe(0)
            ->and($stub)->toContain('// Story::for(Order::class)->group(function () {');

        // Run again: a Storyfeed file is left as it is.
        $this->artisan('storyfeed:install', ['--without-migrations' => true])
            ->expectsOutputToContain('already exists; left as it is')
            ->assertSuccessful();

        // An app's own routes/feed.php (RSS, a feed page) is never touched.
        file_put_contents($path, "<?php\n\nRoute::get('/feed.xml', RssController::class);\n");

        $this->artisan('storyfeed:install', ['--without-migrations' => true])
            ->expectsOutputToContain("isn't a Storyfeed file")
            ->assertSuccessful();

        expect((string) file_get_contents($path))->toContain('RssController');
    } finally {
        if (! $hadConfig) {
            @unlink($published);
        }
    }
});

it('publishes the stub with the storyfeed-definitions tag', function () {
    $paths = ServiceProvider::pathsToPublish(StoryfeedServiceProvider::class, 'storyfeed-definitions');

    expect(array_values($paths))->toBe([base_path('routes/feed.php')])
        ->and(basename((string) array_key_first($paths)))->toBe('definitions.stub');
});
