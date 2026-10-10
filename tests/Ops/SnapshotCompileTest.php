<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\SnapshotCompiler;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasSorted;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

afterEach(function () {
    @unlink(app(SnapshotCompiler::class)->path());
});

/** Count calls to the bounded pass without running it. */
function countSnapshotPasses(): object
{
    $passes = new class
    {
        public int $count = 0;
    };

    app(Kernel::class)->registerCommand(new class($passes) extends Command
    {
        protected $signature = 'storyfeed:cache-snapshots';

        public function __construct(private object $passes)
        {
            parent::__construct();
        }

        public function handle(): void
        {
            $this->passes->count++;
        }
    });

    return $passes;
}

function freshSnapshotCompiler(): SnapshotCompiler
{
    app()->forgetScopedInstances();

    return app(SnapshotCompiler::class);
}

it('defaults to cached and compiles nothing on read', function () {
    $passes = countSnapshotPasses();

    Storyfeed::feed()->get();

    expect(app(SnapshotCompiler::class)->mode())->toBe('cached')
        ->and($passes->count)->toBe(0)
        ->and(is_file(app(SnapshotCompiler::class)->path()))->toBeFalse();
});

it('refuses a mode it does not know', function () {
    config()->set('storyfeed.snapshots.compile', 'live');

    expect(fn () => Storyfeed::feed()->get())->toThrow(InvalidArgumentException::class, 'Snapshot compile mode [live] is not supported');
});

it('recompiles once when the fingerprint changes, and not again until it does', function () {
    config()->set('storyfeed.snapshots.compile', 'sync');
    $passes = countSnapshotPasses();

    Storyfeed::feed()->get();
    Storyfeed::feed()->get();
    expect($passes->count)->toBe(1);

    // The next request: nothing changed.
    freshSnapshotCompiler();
    Storyfeed::feed()->get();
    expect($passes->count)->toBe(1);

    // A source file changed since the last pass.
    file_put_contents(app(SnapshotCompiler::class)->path(), 'stale');
    freshSnapshotCompiler();
    Storyfeed::feed()->get();
    expect($passes->count)->toBe(2);
});

it('fingerprints the Feedable model files, their parents and traits, and Story classes', function () {
    Story::verb('sort', DeliveryWasSorted::class);
    $files = app(SnapshotCompiler::class)->files();

    expect($files)->toContain((new ReflectionClass(Delivery::class))->getFileName())
        ->toContain((new ReflectionClass(User::class))->getFileName())
        ->toContain((new ReflectionClass(Model::class))->getFileName())
        ->toContain((new ReflectionClass(DeliveryWasSorted::class))->getFileName());
});

it('changes the fingerprint when a file is modified', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-fingerprint-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, '<?php');
    touch($path, time() - 60);

    $compiler = new class(app(), app(StoryfeedManager::class), app(Feedables::class), $path) extends SnapshotCompiler
    {
        public function __construct($app, $storyfeed, $feedables, private string $file)
        {
            parent::__construct($app, $storyfeed, $feedables);
        }

        public function files(): array
        {
            return [$this->file];
        }
    };

    $before = $compiler->fingerprint();
    expect($compiler->fingerprint())->toBe($before);

    touch($path, time());
    expect($compiler->fingerprint())->not->toBe($before);
    unlink($path);
});

it('shows a toFeed() change on the next read in sync mode', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();
    DB::table('feed_snapshots')->where('model_type', 'delivery')->update(['label' => 'Before the edit']);

    config()->set('storyfeed.snapshots.compile', 'sync');
    $page = Storyfeed::feed()->get();

    expect(DB::table('feed_snapshots')->where('model_type', 'delivery')->value('label'))->toBe('Delivery #TN-1')
        ->and(json_encode($page->toArray()))->toContain('Delivery #TN-1')->not->toContain('Before the edit');
});

it('warns about sync outside local and testing, and shows the mode', function () {
    config()->set('storyfeed.snapshots.compile', 'sync');
    expect(collect(Storyfeed::doctor(['snapshots'])->all())->pluck('code')->all())->toBe([]);

    app()->detectEnvironment(fn () => 'production');
    $findings = collect(Storyfeed::doctor(['snapshots'])->all());
    expect($findings->pluck('code')->all())->toBe(['snapshots.sync'])
        ->and($findings->first()->subject)->toBe(['environment' => 'production']);

    $this->artisan('storyfeed:doctor --only=snapshots')
        ->expectsOutputToContain('Snapshots compile: sync (STORYFEED_SNAPSHOTS)')
        ->assertSuccessful();

    app()->detectEnvironment(fn () => 'testing');
    config()->set('storyfeed.snapshots.compile', 'live');
    expect(collect(Storyfeed::doctor(['snapshots'])->all())->pluck('code')->all())->toBe(['snapshots.mode']);
});

it('lists the mode in about', function () {
    config()->set('storyfeed.snapshots.compile', 'sync');
    Artisan::call('about', ['--only' => 'storyfeed', '--json' => true]);

    expect(json_decode(Artisan::output(), true)['storyfeed']['snapshots'])->toBe('sync');
});

it('writes the line to .env, comments it in .env.example, and leaves an existing one alone', function (string $eol) {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-env-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("{$directory}/.env", "APP_ENV=local{$eol}QUEUE_CONNECTION=sync");
    file_put_contents("{$directory}/.env.example", "APP_ENV=local{$eol}");
    $this->app->useEnvironmentPath($directory);
    config()->set('storyfeed.definitions', false);
    app(Kernel::class)->registerCommand(new class extends Command
    {
        protected $signature = 'vendor:publish {--tag=}';

        public function handle(): void {}
    });

    $this->artisan('storyfeed:install', ['--without-migrations' => true])
        ->expectsOutputToContain('STORYFEED_SNAPSHOTS=sync')
        ->assertSuccessful();
    $this->artisan('storyfeed:install', ['--without-migrations' => true])->assertSuccessful();

    expect(file_get_contents("{$directory}/.env"))->toBe("APP_ENV=local{$eol}QUEUE_CONNECTION=sync{$eol}STORYFEED_SNAPSHOTS=sync{$eol}")
        ->and(file_get_contents("{$directory}/.env.example"))->toBe("APP_ENV=local{$eol}# STORYFEED_SNAPSHOTS=sync{$eol}");

    array_map(unlink(...), glob("{$directory}/.env*"));
    rmdir($directory);
})->with(['lf' => "\n", 'crlf' => "\r\n"]);
