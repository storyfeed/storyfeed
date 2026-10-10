<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Exceptions\UnknownFeed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Testing\FeedAudience;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * "This feed cannot render this verb", as something an app can assert.
 *
 * The test that matters most here is the last one: the PHP predicate behind
 * these assertions and the SQL the read path actually runs must agree on every
 * pattern, or this whole surface is a test that passes while the feed leaks.
 */
it('asserts a feed refuses an internal verb', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_placed', 'order_delivered'])]);

    FeedAudience::assertRefuses('customer', 'order_margin_note');
    FeedAudience::assertAllows('customer', ['order_placed', 'order_delivered']);
});

it('fails when a verb the feed was meant to refuse would render', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_*'])]);

    expect(fn () => FeedAudience::assertRefuses('customer', 'order_margin_note'))
        ->toThrow(AssertionFailedError::class, 'order_margin_note');
});

it('says WHY an unrestricted feed shows everything', function () {
    Storyfeed::feeds(['admin' => fn (FeedBuilder $feed) => $feed->live()]);

    expect(fn () => FeedAudience::assertRefuses('admin', 'order_margin_note'))
        ->toThrow(AssertionFailedError::class, 'declares no only()/except() at all');
});

it('reads a denylist the same way the read path does', function () {
    Storyfeed::feeds(['admin' => fn (FeedBuilder $feed) => $feed->except(['order_margin_note', 'internal_*'])]);

    FeedAudience::assertRefuses('admin', ['order_margin_note', 'internal_note']);
    FeedAudience::assertAllows('admin', ['order_placed', 'internalish_note']);
});

it('takes FeedVerb cases and plain backed enum cases, like only() does', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->only([ActivityVerb::Confirm])]);

    FeedAudience::assertAllows('kitchen', ActivityVerb::Confirm);
    FeedAudience::assertRefuses('kitchen', ActivityVerb::Upload);
});

it('sees a single verb() as the restriction it is', function () {
    Storyfeed::feeds(['confirmations' => fn (FeedBuilder $feed) => $feed->verb('confirm')]);

    FeedAudience::assertAllows('confirmations', 'confirm');
    FeedAudience::assertRefuses('confirmations', 'upload');
});

it('inspects a subject feed it cannot construct', function () {
    Storyfeed::feeds(['customer' => CustomerFeed::class]);

    // CustomerFeed takes its Customer through the constructor, so nothing
    // holding only the name can build one — but define() is still readable,
    // which is the whole reason the hooks are separate.
    FeedAudience::assertRefuses('customer', 'order_margin_note');
    FeedAudience::assertAllows('customer', 'order_placed');
});

it('resolves a Feed class-string without registration', function () {
    FeedAudience::assertRefuses(CustomerFeed::class, 'order_margin_note');
});

it('throws for a feed nobody registered', function () {
    expect(fn () => FeedAudience::assertRefuses('nope', 'order_placed'))
        ->toThrow(UnknownFeed::class);
});

it('pins the whole allowlist against the declared vocabulary', function () {
    Storyfeed::verbs([
        'order_placed' => ActivityType::Create,
        'order_delivered' => ActivityType::Arrive,
        'order_margin_note' => ActivityType::Create,
    ]);
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_placed', 'order_delivered'])]);

    FeedAudience::assertAllowsOnly('customer', ['order_placed', 'order_delivered']);

    // The verb nobody thought about, added six months later.
    Storyfeed::verbs(['order_internal_cost' => ActivityType::Create]);
    Storyfeed::feeds(['loose' => fn (FeedBuilder $feed) => $feed->only(['order_*'])]);

    expect(fn () => FeedAudience::assertAllowsOnly('loose', ['order_placed', 'order_delivered']))
        ->toThrow(AssertionFailedError::class, 'order_internal_cost');
});

it('pins the allowlist against what was actually recorded, under the fake', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_*'])]);
    Storyfeed::fake();

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('order_placed', $delivery)->publish();
    Storyfeed::activity('order_margin_note', $delivery)->publish();

    expect(fn () => FeedAudience::assertAllowsOnly('customer', ['order_placed']))
        ->toThrow(AssertionFailedError::class, 'order_margin_note');
});

it('pins the allowlist against real tables too', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_*'])]);

    $customer = Customer::create(['name' => 'Order 1001']);

    Storyfeed::activity('order_placed', $customer)->publish();
    Storyfeed::activity('order_margin_note', $customer)->publish();

    expect(fn () => FeedAudience::assertAllowsOnly('customer', ['order_placed']))
        ->toThrow(AssertionFailedError::class, 'order_margin_note');
});

it('refuses to pin an allowlist with no vocabulary and nothing recorded', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_placed'])]);

    expect(fn () => FeedAudience::assertAllowsOnly('customer', ['order_placed']))
        ->toThrow(AssertionFailedError::class, 'proves nothing');
});

it('fails a pin that has rotted into verbs the feed stopped showing', function () {
    Storyfeed::verbs(['order_placed' => ActivityType::Create]);
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order_placed'])]);

    expect(fn () => FeedAudience::assertAllowsOnly('customer', ['order_placed', 'order_delivered']))
        ->toThrow(AssertionFailedError::class, 'refuses verbs it was expected to show');
});

it('cannot see narrowing done inside query(), and fails in the safe direction', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->query(
            fn (ActivityBuilder $query) => $query->where('verb', '!=', 'order_margin_note'),
        ),
    ]);

    // The feed genuinely refuses the verb; the declaration does not say so.
    expect(fn () => FeedAudience::assertRefuses('customer', 'order_margin_note'))
        ->toThrow(AssertionFailedError::class, 'query()');
});

it('agrees with the SQL the read path runs, pattern for pattern', function () {
    $customer = Customer::create(['name' => 'Order 1001']);
    $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);

    $verbs = ['order_placed', 'order_margin_note', 'orderly', 'order', 'confirm', 'internal_note', 'a%b_leak'];

    foreach ($verbs as $verb) {
        Storyfeed::activity()->actor($ines)->verb($verb, $customer)->publish();
    }

    $presets = [
        fn (FeedBuilder $feed) => $feed->only(['order_placed']),
        fn (FeedBuilder $feed) => $feed->only(['order_*']),
        fn (FeedBuilder $feed) => $feed->except(['order_margin_note']),
        fn (FeedBuilder $feed) => $feed->except(['order_*']),
        fn (FeedBuilder $feed) => $feed->only(['order_*'])->except(['order_margin_note']),
        fn (FeedBuilder $feed) => $feed->only(['order_*'])->only(['*']),
        fn (FeedBuilder $feed) => $feed->only(['a%b_*']),
        fn (FeedBuilder $feed) => $feed->verb('confirm'),
    ];

    foreach ($presets as $index => $preset) {
        Storyfeed::feeds(['probe' => $preset], merge: false);

        $rendered = collect(Storyfeed::feed('probe')->log()->limit(50)->get()->toArray())
            ->pluck('verb')->sort()->values()->all();

        $predicted = collect($verbs)
            ->filter(fn (string $verb) => Storyfeed::feedDefinition('probe')->inspect()->admits($verb))
            ->sort()->values()->all();

        expect($predicted)->toBe($rendered, "preset #{$index} disagrees with the SQL");
    }
});
