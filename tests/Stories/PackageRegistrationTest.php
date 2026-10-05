<?php

namespace Storyfeed\Tests\Stories;

use BackedEnum;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\Stories\Story as Message;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

class OverridePackageActions
{
    public function ship(Verb $verb, Request $request): Verb
    {
        return $verb->headline(':actor shipped :object')->icon(OverridePackageProvider::$icon)
            ->casts(['amount' => 'decimal:2', 'quantity' => 'integer'])
            ->whereActor('user', 'storyfeed.party')->whereObject('delivery')
            ->name('package.ship')->onConnection('redis')->onQueue('package')
            ->groups(
                Group::repeat()->headline(':actor shipped :count deliveries'),
                Group::byObject()->headline(':actor shipped :object :count times'),
            )->actor($request->input('provider', 'Package'));
    }
}

class OverridePackageMessage extends Message
{
    public string|array|null $objectType = 'delivery';

    public string|FeedVerb|BackedEnum|null $verb = 'confirm';

    public function __construct(public Delivery $delivery, public User $user) {}

    public function headline(): string
    {
        return ':actor confirmed :object';
    }

    public function icon(): ?string
    {
        return 'check';
    }

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery)->by($this->user);
    }
}

class OverridePackageProvider extends ServiceProvider
{
    public static int $boots = 0;

    public static string $icon = 'truck';

    public function boot(): void
    {
        self::$boots++;
        Story::for('delivery')->verb('inspect')->headline(':actor inspected :object');
        Story::resource('delivery', OverridePackageActions::class)->only('ship');
        Story::for('delivery')->verb('confirm', OverridePackageMessage::class);
    }
}

class OverrideApplicationProvider extends ServiceProvider
{
    public function boot(): void
    {
        Story::for('delivery')->verb('ship')->override()->headline(':actor sent :object');
        Story::for('delivery')->verb('confirm')->override()->headline(':actor accepted :object');
    }
}

/** Real provider boot and disk cache lifecycle; all artifacts stay in this worktree. */
final class PackageRegistrationTest extends TestCase
{
    private ?string $sandbox = null;

    private string|false $definitions = false;

    private bool $withApp = false;

    private bool $appFirst = false;

    protected function setUp(): void
    {
        OverridePackageProvider::$boots = 0;
        OverridePackageProvider::$icon = 'truck';
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        $providers = $this->withApp
            ? ($this->appFirst
                ? [OverrideApplicationProvider::class, OverridePackageProvider::class]
                : [OverridePackageProvider::class, OverrideApplicationProvider::class])
            : [OverridePackageProvider::class];

        return [...parent::getPackageProviders($app), ...$providers];
    }

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        if ($this->sandbox === null) {
            $this->sandbox = dirname(__DIR__, 2).'/build/package-overrides-'.bin2hex(random_bytes(6));
            $checkout = realpath(dirname(__DIR__, 2));
            if (! is_dir($checkout.'/build')) {
                mkdir($checkout.'/build', 0755);
            }
            // Assert the canonical parent BEFORE creating artifacts: a ../
            // or a build symlink must never escape the owned worktree.
            self::assertSame($checkout.'/build', realpath(dirname($this->sandbox)));
            mkdir($this->sandbox.'/bootstrap/cache', 0755, true);
        }
        self::assertStringStartsWith(realpath(dirname(__DIR__, 2)).'/build/', realpath($this->sandbox));
        $app->useBootstrapPath($this->sandbox.'/bootstrap');
        config()->set('storyfeed.definitions', $this->definitions);
    }

    private function reboot(): void
    {
        $this->refreshApplication();
        Relation::enforceMorphMap(['delivery' => Delivery::class, 'user' => User::class]);
        $this->defineDatabaseMigrations();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->sandbox !== null && is_dir($this->sandbox)) {
                self::assertStringStartsWith(realpath(dirname(__DIR__, 2)).'/build/', realpath($this->sandbox));
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($this->sandbox);
            }
        } finally {
            OverridePackageProvider::$icon = 'truck';
            parent::tearDown();
        }
    }

    public static function fileModes(): array
    {
        return ['disabled' => [false], 'missing' => [true]];
    }

    #[DataProvider('fileModes')]
    public function test_modern_package_provider_registers_without_an_application_file(bool $missing): void
    {
        if ($missing) {
            $this->definitions = $this->sandbox.'/missing-feed.php';
            $this->reboot();
        }

        expect(app(DefinitionsFile::class)->exists())->toBeFalse()
            ->and(OverridePackageProvider::$boots)->toBeGreaterThan(0)
            ->and(Storyfeed::template('delivery', 'inspect'))->toBe(':actor inspected :object')
            ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck')
            ->and(Storyfeed::storyVerb(OverridePackageMessage::class))->toBe('confirm');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true));
        expect($rows->firstWhere('verb', 'inspect')['source'])->toMatch('/PackageRegistrationTest\.php:\d+$/')
            ->and($rows->firstWhere('verb', 'inspect')['override'])->toBeFalse();

        $this->artisan('storyfeed:cache')->assertSuccessful();
        expect(is_file(app(StoryManifest::class)->path()))->toBeTrue();
        $this->reboot();
        expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck');
    }

    public static function providerOrders(): array
    {
        return ['package first' => [false], 'application first' => [true]];
    }

    #[DataProvider('providerOrders')]
    public function test_provider_overlays_survive_uncached_and_cached_boot_in_both_orders(bool $appFirst): void
    {
        $this->withApp = true;
        $this->appFirst = $appFirst;
        $this->reboot();
        $originalAction = Storyfeed::storyActions()['delivery.ship'];
        expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor sent :object')
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck')
            ->and(Storyfeed::aggregateTemplate('repeat', 'ship', 'delivery'))->toBe(':actor shipped :count deliveries');
        $this->assertPackagePublishesWithOverlay();

        $this->artisan('storyfeed:cache')->assertSuccessful();
        $manifest = app(StoryManifest::class);
        expect($manifest->path())->toStartWith($this->sandbox.'/bootstrap/cache/')
            ->and($manifest->read()['grammar']['delivery.ship'])->toBe(':actor sent :object');
        $boots = OverridePackageProvider::$boots;
        $this->reboot();

        expect(OverridePackageProvider::$boots)->toBe($boots + 1)
            ->and(Storyfeed::storyActions()['delivery.ship'])->toBe($originalAction)
            ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor sent :object')
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck')
            ->and(Storyfeed::aggregateTemplate('object', 'ship', 'delivery'))->toBe(':actor shipped :object :count times')
            ->and(Storyfeed::dataCasts('delivery', 'ship'))->toBe(['amount' => 'decimal:2', 'quantity' => 'integer'])
            ->and(Storyfeed::wheres('delivery', 'ship')['object'])->toBe(['delivery'])
            ->and(Storyfeed::queueing('delivery', 'ship')['connection'])->toBe('redis');
        $this->assertPackagePublishesWithOverlay();

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true));
        $overlay = $rows->first(fn ($row) => $row['verb'] === 'ship' && $row['override']);
        expect($rows->where('verb', 'ship'))->toHaveCount(2)
            ->and($overlay['source'])->toMatch('/PackageRegistrationTest\.php:\d+$/')
            ->and($overlay['headline'])->toBe(':actor sent :object')
            ->and($overlay['action'])->toBeNull();
    }

    private function assertPackagePublishesWithOverlay(): void
    {
        config()->set('storyfeed.grammar.strict', true);
        app()->instance('request', Request::create('/ship', 'POST', ['provider' => 'Stripe']));
        $delivery = Delivery::create(['tracking_number' => 'TN-'.bin2hex(random_bytes(3))]);
        $activity = Storyfeed::activity('ship', $delivery)->publish();
        expect($activity->actor->name)->toBe('Stripe');
        $items = Storyfeed::feed()->log()->get()->toArray()['items'];
        expect(collect($items)->firstWhere('verb', 'ship')['headline_template'])->toBe(':actor sent :object');

        $user = User::create(['name' => 'Sally', 'email' => bin2hex(random_bytes(3)).'@example.com']);
        $messageActivity = Storyfeed::publish(new OverridePackageMessage($delivery, $user));
        expect($messageActivity->verb)->toBe('confirm')
            ->and($messageActivity->actor->is($user))->toBeTrue()
            ->and(Storyfeed::template('delivery', 'confirm'))->toBe(':actor accepted :object');
    }

    public function test_app_file_overlay_is_skipped_at_cached_boot_but_retained_in_the_manifest(): void
    {
        $this->definitions = $this->sandbox.'/feed.php';
        file_put_contents($this->definitions, <<<'PHP'
            <?php
            \Storyfeed\Facades\Story::for('delivery')->verb('ship')->override()->headline(':actor sent :object');
            PHP);
        $this->reboot();
        expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor sent :object');
        $action = Storyfeed::storyActions()['delivery.ship'];
        $this->artisan('storyfeed:cache')->assertSuccessful();
        $this->reboot();

        expect(app(DefinitionsFile::class)->isLoaded())->toBeFalse()
            ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor sent :object')
            ->and(app(DefinitionsFile::class)->isLoaded())->toBeFalse()
            ->and(Storyfeed::storyActions()['delivery.ship'])->toBe($action)
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true));
        $overlay = $rows->first(fn ($row) => $row['override']);
        expect(app(DefinitionsFile::class)->isLoaded())->toBeTrue()
            ->and($overlay['source'])->toEndWith('/feed.php:2')
            ->and($overlay['headline'])->toBe(':actor sent :object');
    }

    public function test_provider_changes_keep_cached_runtime_until_refresh_and_report_drift(): void
    {
        $this->withApp = true;
        $this->reboot();
        $this->artisan('storyfeed:cache')->assertSuccessful();
        OverridePackageProvider::$icon = 'new-icon';
        $this->reboot();

        expect(Storyfeed::icon('delivery', 'ship'))->toBe('truck')
            ->and(Storyfeed::compiledStories()['icons']['delivery.ship'])->toBe('new-icon')
            ->and(Storyfeed::doctor(['manifest'])->has('manifest.stale'))->toBeTrue()
            ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck');

        $this->artisan('storyfeed:cache')->assertSuccessful();
        $this->reboot();
        expect(Storyfeed::icon('delivery', 'ship'))->toBe('new-icon')
            ->and(Storyfeed::doctor(['manifest'])->has('manifest.stale'))->toBeFalse();
    }
}
