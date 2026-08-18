<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/*
 * make:feed — a generator whose real product is the TYPED CONSTRUCTOR.
 *
 * The subject being a constructor argument is what makes an unscoped build
 * impossible, and writing that argument is the step people skip. Same doctrine
 * as make:story: the guarantee goes into the generated file as a literal, where
 * a wrong guess is visible in the diff, rather than into runtime inference.
 *
 * The load-bearing test is the last one. --from-doctor must NOT be able to make
 * storyfeed:doctor pass, because deciding who may see a verb is the one thing a
 * generator cannot do.
 */

function feedPath(string $class): string
{
    return app()->path("Feeds/{$class}.php");
}

afterEach(function () {
    foreach (glob(app()->path('Feeds/*.php')) ?: [] as $file) {
        unlink($file);
    }
});

it('appends the Feed suffix rather than renaming you quietly', function () {
    $this->artisan('make:feed', ['name' => 'Customer'])->assertSuccessful();

    expect(file_exists(feedPath('CustomerFeed')))->toBeTrue();
});

it('writes a typed constructor and a scope() that binds it', function () {
    $this->artisan('make:feed', [
        'name' => 'Customer',
        '--subject' => Delivery::class,
        '--role' => 'context',
        '--only' => 'order.placed,order.delivered',
        '--mode' => 'log',
    ])->assertSuccessful();

    expect(file_get_contents(feedPath('CustomerFeed')))
        ->toContain('public function __construct(protected \Workbench\App\Models\Delivery $delivery) {}')
        ->toContain('$feed->context($this->delivery);')
        ->toContain("'order.placed',")
        ->toContain('->log()');
});

it('generates a global feed — no constructor, no scope — when given no subject', function () {
    $this->artisan('make:feed', ['name' => 'Admin', '--only' => 'order.placed'])->assertSuccessful();

    expect(file_get_contents(feedPath('AdminFeed')))
        ->not->toContain('__construct')
        ->not->toContain('function scope');
});

it('tells you to register it, because that is what doctor needs', function () {
    $this->artisan('make:feed', ['name' => 'Customer'])
        ->expectsOutputToContain('storyfeed:doctor')
        ->assertSuccessful();
});

it('rejects an unknown mode and an unknown role', function () {
    $this->artisan('make:feed', ['name' => 'Customer', '--mode' => 'curated'])->assertFailed();
    $this->artisan('make:feed', ['name' => 'Customer', '--role' => 'audience'])->assertFailed();
});

it('transcribes doctor\'s undecided verbs — commented, so the file cannot make the check pass', function () {
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['confirm'])]);

    $this->artisan('make:feed', ['name' => 'Customer', '--from-doctor' => true, '--force' => true])
        ->expectsOutputToContain('undecided')
        ->assertSuccessful();

    $source = file_get_contents(feedPath('CustomerFeed'));

    // Every undecided verb arrives commented out. One feed, not one per verb:
    // a generated single-verb restricted feed would MENTION its verb, which is
    // exactly what FeedCoverage counts as decided — the generator would turn
    // the check green while nobody decided anything.
    expect($source)
        ->toContain("// 'comment',")
        ->toContain('Move each one into')
        ->and(glob(app()->path('Feeds/*.php')))->toHaveCount(1);

    // And the file it wrote is not a valid allowlist yet: only([]) throws.
    expect(preg_match('/only\(\[\s*\/\//', $source))->toBe(1);
});
