<?php

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryDefinition;
use Storyfeed\Support\SurfaceScanner;
use Storyfeed\Testing\StorySurface;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The inventory: what publishes, and what could but doesn't.
 *
 * This is the answer to "a developer joining in six months cannot discover what
 * the feed publishes or from where" — discoverability as a TOOL rather than a
 * filing convention, so call sites stay wherever they belong.
 */

it('names the story class as the source of what it publishes', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)
        ->publish();

    // The whole point for a developer joining in six months: the inventory
    // says WHERE the story is defined.
    $this->artisan('storyfeed:stories')
        ->expectsOutputToContain('DeliveryWasConfirmed')
        ->assertSuccessful();
});

it('reports pairs recorded from call sites the package never saw', function () {
    // The inventory must describe the APP, not only the part that adopted the
    // Story layer — otherwise it is a list of what already migrated.
    Storyfeed::grammar(['delivery.archive' => ':actor archived :object']);

    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Artisan::call('storyfeed:stories', ['--json' => true]);

    $rows = json_decode(Artisan::output(), true);

    $archive = collect($rows)->firstWhere('verb', 'archive');

    expect($archive)->not->toBeNull()
        // Named as a call site rather than omitted: a developer reading this
        // needs to know the publish exists even though no Story describes it.
        ->and($archive['source'])->toBe('(call site)')
        ->and($archive['grammar'])->toBeTrue();
});

it('marks a recorded pair with no grammar as unauthored', function () {
    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('unauthored')
        ->assertSuccessful();
});

it('marks an authored-but-never-recorded story as dead', function () {
    Storyfeed::stories([
        StoryDefinition::make('delivery.confirm')->headline(':actor confirmed :object'),
    ]);

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('dead')
        ->assertSuccessful();
});

it('marks a story missing an applicable axis as a gap', function () {
    Storyfeed::stories([
        // Authored singular, and only ONE of the axes `upload` can reach.
        StoryDefinition::make('delivery.upload')
            ->headline(':actor uploaded :object')
            ->groups(Group::repeat()->headline(':actor uploaded :count')),
    ]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    Storyfeed::activity()->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publish();

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('gap')
        // Points at the command that prints the fix.
        ->expectsOutputToContain('storyfeed:doctor --stubs')
        ->assertSuccessful();
});

it('reports declared surface as unwired when nothing has published', function () {
    // The workbench declares three Feedable models and publishes nothing, so
    // the useful answer is "these three are unwired" — NOT "nothing publishes",
    // which would be the report telling you it has no news when it does.
    // Order matters to the helper: each expectation consumes the first
    // matching line, and one table row contains both words.
    $this->artisan('storyfeed:stories')
        ->expectsOutputToContain('Delivery')
        ->expectsOutputToContain('unwired')
        ->assertSuccessful();
});

it('reports every story as ok when there is nothing to flag', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // The fixture authors three of the four axes `confirm` can reach, so the
    // fourth has to be filled in for a clean run — which is itself the
    // derivation working: nobody hand-listed which axes apply.
    Storyfeed::aggregateGrammar(['object.confirm' => ':actor confirmed :object :count times']);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->publish();

    // And every Feedable model is now in the feed in SOME role — the actor and
    // target count, not just the object.
    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('Every story is ok')
        ->assertSuccessful();
});

it('warns when the newest activity is older than --since', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)
        ->publishedAt(now()->subDays(90))
        ->publish();

    $this->artisan('storyfeed:stories --since=30')
        ->expectsOutputToContain('may have stopped growing')
        ->assertSuccessful();
});

it('asserts no declared surface is silent, and names it when some is', function () {
    // Nothing published at all, so every Feedable workbench model is unwired.
    try {
        StorySurface::assertNoUnwiredSurface();
        $this->fail('Expected unwired surface to be reported.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('declared but publishes nothing')
            ->toContain('Delivery')
            // States its own reach rather than implying completeness.
            ->toContain('invisible to Storyfeed');
    }
});

it('accepts deliberately silent surface via $except', function () {
    $feedable = app(SurfaceScanner::class)->scan()['feedable'];

    StorySurface::assertNoUnwiredSurface(except: $feedable);
});
