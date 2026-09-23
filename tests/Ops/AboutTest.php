<?php

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\WithConfig;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Tests\TestCase;

/*
 * The `Storyfeed` section of `php artisan about`: state made visible, the way
 * `about` shows CACHED for config. Every row has to be true on a fresh
 * install, so most of these tests are about what it says when nothing is
 * there — no file, no manifest, no tables, no database.
 */

/** Run `about --only=storyfeed --json` and return the section. */
function aboutStoryfeed(): array
{
    Artisan::call('about', ['--only' => 'storyfeed', '--json' => true]);

    return json_decode(Artisan::output(), true)['storyfeed'];
}

function aboutDefinitionsFile(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-about-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, "<?php\n\nuse Storyfeed\\Facades\\Story;\nuse Workbench\\App\\Models\\Delivery;\n\n"
        ."Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object');\n");

    $GLOBALS['storyfeedAboutFiles'][] = $path;

    return $path;
}

function rebootWithDefinitions(TestCase $test, string $path): void
{
    $test::usesTestingFeature(new WithConfig('storyfeed.definitions', $path));

    // One process boots once; a reboot here must not leave the previous
    // app's section registered beside the new one.
    AboutCommand::flushState();

    (fn () => $this->refreshApplication())->call($test);
}

afterEach(function () {
    app(StoryManifest::class)->delete();

    // usesTestingFeature() is class-wide, so a reboot's config would leak
    // into every later test in this file.
    (fn () => static::$testCaseTestingFeatures = [])->call($this);

    foreach ($GLOBALS['storyfeedAboutFiles'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['storyfeedAboutFiles'] = [];
});

it('renders a Storyfeed section on a fresh install', function () {
    $this->artisan('about', ['--only' => 'storyfeed'])
        ->expectsOutputToContain('Storyfeed')
        ->expectsOutputToContain('NOT CACHED')
        ->expectsOutputToContain('storyfeed:doctor runs all')
        ->assertSuccessful();

    $about = aboutStoryfeed();

    expect($about['definitions'])->toStartWith('none (')
        ->and($about['cache'])->toBeFalse()
        ->and($about['stories'])->toBe(0)
        ->and($about['feeds'])->toBe(0)
        ->and($about['verbs'])->toBe(0)
        // Only the cheap checks ran, and on a migrated schema they are clean.
        ->and($about['doctor'])->toBe([]);
});

it('reports the package schedule, and what the app has not scheduled', function () {
    $about = aboutStoryfeed();

    expect($about['curate_schedule'])->toBe('0 * * * *')
        ->and($about['trickle_schedule'])->toBeNull()
        ->and($about['close-batches_schedule'])->toBeNull();
});

it('says when the curate schedule is switched off', function () {
    $this::usesTestingFeature(new WithConfig('storyfeed.curate.schedule', false));
    AboutCommand::flushState();
    (fn () => $this->refreshApplication())->call($this);

    expect(aboutStoryfeed()['curate_schedule'])->toBeNull();
});

it('names a loaded definitions file and counts what it declared', function () {
    rebootWithDefinitions($this, $path = aboutDefinitionsFile());

    $about = aboutStoryfeed();

    expect($about['definitions'])->toBe($path)
        ->and($about['cache'])->toBeFalse()
        ->and($about['verbs'])->toBe(1);
});

it('says the file is cached and not loaded, and since when', function () {
    rebootWithDefinitions($this, $path = aboutDefinitionsFile());

    $this->artisan('storyfeed:cache')->assertSuccessful();

    // A new process: the manifest exists at boot, so the file is skipped.
    rebootWithDefinitions($this, $path);

    $about = aboutStoryfeed();

    expect($about['definitions'])->toBe($path.' (cached — not loaded at boot)')
        ->and($about['cache'])->toBe(date('Y-m-d H:i:s', filemtime(app(StoryManifest::class)->path())))
        ->and($about['verbs'])->toBe(1);

    $this->artisan('about', ['--only' => 'storyfeed'])->expectsOutputToContain('CACHED')->assertSuccessful();
});

it('reports a stale manifest through the doctor row', function () {
    rebootWithDefinitions($this, $path = aboutDefinitionsFile());
    $this->artisan('storyfeed:cache')->assertSuccessful();

    // The 985 shape: the source changed after caching, and nothing was re-run.
    file_put_contents($path, str_replace('shipped', 'dispatched', file_get_contents($path)));
    rebootWithDefinitions($this, $path);

    expect(aboutStoryfeed()['doctor'])->toContain('manifest.stale');
});

it('reports missing tables without querying them', function () {
    foreach (config('storyfeed.tables') as $table) {
        Schema::dropIfExists($table);
    }

    expect(aboutStoryfeed()['doctor'])->toBe(['tables.missing']);

    $this->artisan('about', ['--only' => 'storyfeed'])
        ->expectsOutputToContain('tables.missing')
        ->assertSuccessful();
});

it('survives having no database at all', function () {
    config()->set('database.connections.unreachable', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-no-such-dir'.DIRECTORY_SEPARATOR.'none.sqlite',
    ]);
    config()->set('database.default', 'unreachable');

    // The throwing check becomes a finding, as it does in the doctor; the
    // rest of the section is config and still renders.
    expect(aboutStoryfeed()['doctor'])->toBe(['doctor.check_failed']);

    $this->artisan('about', ['--only' => 'storyfeed'])->assertSuccessful();
});
