<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\StoryfeedManager;
use Storyfeed\Tests\Fixtures\Stories\RefundStory;
use Storyfeed\Tests\Fixtures\Stories\VaryingStory;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * An action that takes the request: compiled once with a blank one, and run
 * again at each publish, where only its ->actor() is used. Everything the
 * feed, the list, the doctor and the cache read comes from the compiled run.
 */

beforeEach(function () {
    RefundStory::$seen = [];
    $this->delivery = Delivery::create(['tracking_number' => 'T1']);
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

afterEach(function () {
    app(StoryManifest::class)->delete();
});

/** Bind a live request, as the HTTP kernel does before providers boot. */
function liveRequest(array $input): void
{
    app()->instance('request', Request::create('/refunds', 'POST', $input));
}

it('compiles with a blank request, never the one the container holds', function () {
    liveRequest(['provider' => 'Paddle']);

    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    Storyfeed::compileStories();

    expect(RefundStory::$seen)->toBe([null])
        ->and(Storyfeed::template('delivery', 'refund'))->toBe(':actor refunded :object');
});

it('runs again at each publish, and the actor it chooses is the activity\'s', function () {
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    Storyfeed::compileStories();

    liveRequest(['provider' => 'Stripe']);
    $first = Storyfeed::activity('refund', $this->delivery)->publish();

    liveRequest(['provider' => 'Paddle']);
    $second = Storyfeed::activity('refund', $this->delivery)->publish();

    expect(RefundStory::$seen)->toBe([null, 'Stripe', 'Paddle'])
        ->and($first->actor)->toBeInstanceOf(Party::class)
        ->and($first->actor->name)->toBe('Stripe')
        ->and($second->actor->name)->toBe('Paddle');
});

it('says nothing when the request names no actor, so the default actor stands', function () {
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    $this->actingAs($this->ines);

    liveRequest([]);
    $activity = Storyfeed::activity('refund', $this->delivery)->publish();

    expect($activity->actor->is($this->ines))->toBeTrue();
});

it('ranks below the call site and Storyfeed::actor(), and above the signed-in user', function () {
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    Storyfeed::compileStories();
    $this->actingAs($this->ines);
    liveRequest(['provider' => 'Stripe']);

    $explicit = Storyfeed::activity('refund', $this->delivery)->actor(User::create(['name' => 'Dana', 'email' => 'dana@example.com']))->publish();
    $scoped = Storyfeed::actor('System', fn () => Storyfeed::activity('refund', $this->delivery)->publish());
    $action = Storyfeed::activity('refund', $this->delivery)->publish();

    expect($explicit->actor->name)->toBe('Dana')
        ->and($scoped->actor->name)->toBe('System')
        ->and($action->actor->name)->toBe('Stripe')
        // Neither the call site nor the scope needed the action run.
        ->and(RefundStory::$seen)->toBe([null, 'Stripe']);
});

it('takes a fixed party from an action that has no request', function () {
    Story::resource(Delivery::class, RefundStory::class)->only('sync');
    $this->actingAs($this->ines);

    $activity = Storyfeed::activity('sync', $this->delivery)->publish();

    expect($activity->actor->name)->toBe('Stripe')
        ->and(Storyfeed::storyActors())->toBe(['delivery.sync' => 'Stripe']);
});

it('takes a fixed party from the file, on the type → verb ladder', function () {
    Story::verb('import')->headline(':actor imported :object')->actor('Legacy');

    $activity = Storyfeed::activity('import', $this->delivery)->publish();

    expect($activity->actor->name)->toBe('Legacy');
});

it('throws in strict mode when a request changes what the feed reads', function () {
    config(['storyfeed.grammar.strict' => true]);
    Story::resource(Delivery::class, VaryingStory::class)->only('place');
    Storyfeed::compileStories();

    liveRequest(['rush' => 1]);

    Storyfeed::activity('place', $this->delivery)->publish();
})->throws(StoryMisconfigured::class, VaryingStory::class.'@place] returned a different headline for this request');

it('keeps the compiled definition outside strict mode, and reports it once', function () {
    config(['storyfeed.grammar.strict' => false]);
    Exceptions::fake();
    Story::resource(Delivery::class, VaryingStory::class)->only('place');

    liveRequest(['rush' => 1]);
    Storyfeed::activity('place', $this->delivery)->publish();
    Storyfeed::activity('place', $this->delivery)->publish();

    Exceptions::assertReported(fn (StoryMisconfigured $e) => str_contains($e->getMessage(), 'different headline'));
    Exceptions::assertReportedCount(1);

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['headline_template'])->toBe(':actor placed :object');
});

it('runs from the manifest, with nothing registered at boot', function () {
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    $this->artisan('storyfeed:cache')->assertSuccessful();

    // A fresh manager: nothing registered, as at a cached boot.
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    $storyfeed = app(StoryfeedManager::class);
    app(StoryManifest::class)->apply($storyfeed);
    RefundStory::$seen = [];

    liveRequest(['provider' => 'Stripe']);
    $activity = Storyfeed::activity('refund', $this->delivery)->publish();

    expect($activity->actor->name)->toBe('Stripe')
        // Only the publish ran it: the manifest held what the compile made.
        ->and(RefundStory::$seen)->toBe(['Stripe']);
});
