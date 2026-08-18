<?php

use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Workbench\App\Feeds\AdminFeed;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Feeds\ForgetfulFeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * Feed classes — the SCOPE half of the seam.
 *
 * The closure form (FeedAllowlistTest) proved the allowlist half. This file is
 * about the half that fails OPEN: a feed whose subject a call site can forget,
 * or silently rebind, renders every row its allowlist admits and looks entirely
 * correct doing it. So the tests that matter here are the ones asserting a
 * THROW — that an unscoped build is impossible, and that a rebind is refused
 * rather than quietly winning.
 */
beforeEach(function () {
    $this->mine = Customer::create(['name' => 'Mine']);
    $this->theirs = Customer::create(['name' => 'Theirs']);
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->file = Delivery::create(['tracking_number' => 'menu.pdf']);
});

function classVerbsOf(array $items): array
{
    return collect($items)->pluck('verb')->sort()->values()->all();
}

function record(string $verb, Customer $context): void
{
    Storyfeed::activity()
        ->actor(test()->ines)
        ->verb($verb, test()->file)
        ->context($context)
        ->publish();
}

it('scopes to the subject its constructor was given', function () {
    record('order.placed', $this->mine);
    record('order.placed', $this->theirs);

    $page = CustomerFeed::make($this->mine)->get();

    expect($page->items())->toHaveCount(1)
        ->and($page->items()[0]['context']['id'])->toBe((string) $this->mine->getKey());
});

it('applies the allowlist and the scope together', function () {
    record('order.placed', $this->mine);
    record('order.margin_note', $this->mine);
    record('order.placed', $this->theirs);

    expect(classVerbsOf(CustomerFeed::make($this->mine)->get()->items()))->toBe(['order.placed']);
});

it('cannot be built without its subject — PHP refuses, not us', function () {
    // The guarantee is the constructor's, which is the point of entering
    // through one: no code here enforces it and none can be bypassed.
    expect(fn () => CustomerFeed::make())->toThrow(ArgumentCountError::class);
});

it('refuses to build a subject feed from its registered name', function () {
    Storyfeed::feeds(['customer' => CustomerFeed::class]);

    expect(fn () => Storyfeed::feed('customer'))
        ->toThrow(FeedMisconfigured::class, 'takes constructor arguments');
});

it('refuses to rebind a bound scope, rather than letting it silently win', function () {
    record('order.placed', $this->mine);
    record('order.placed', $this->theirs);

    $feed = CustomerFeed::make($this->mine);

    expect(fn () => $feed->context($this->theirs))
        ->toThrow(FeedMisconfigured::class, 'cannot be rebound at the call site');

    // And the silent version of the same mistake: a role filter is a
    // single-slot assignment, so without the lock this would have replaced the
    // scope and returned the other customer's timeline.
    expect(CustomerFeed::make($this->mine)->get()->items())->toHaveCount(1);
});

it('still lets a call site NARROW a scoped feed', function () {
    record('order.placed', $this->mine);
    record('order.delivered', $this->mine);

    $page = CustomerFeed::make($this->mine)
        ->only(['order.placed'])
        ->query(fn ($q) => $q->whereNotNull('actor_id'))
        ->actor($this->ines)
        ->get();

    expect(classVerbsOf($page->items()))->toBe(['order.placed']);
});

it('throws when a hand-written feed takes a subject and never binds it', function () {
    // The backstop: the typed constructor is generated, but the check that the
    // subject REACHES the query lives in the base class, so a hand-written feed
    // cannot quietly forgo it.
    expect(fn () => ForgetfulFeed::make($this->mine))
        ->toThrow(FeedMisconfigured::class, 'binds no role');
});

it('builds a global feed with no subject at all', function () {
    record('order.placed', $this->mine);
    record('order.margin_note', $this->mine);

    expect(classVerbsOf(AdminFeed::make()->log()->get()->items()))->toBe(['order.placed']);
});

it('has no for(), and says where the name went', function () {
    expect(fn () => CustomerFeed::for($this->mine))
        ->toThrow(FeedMisconfigured::class, 'Feed classes have no for()');
});

it('produces the same query as the equivalent closure preset', function () {
    // The two authoring forms compile to one registry. If this ever diverges,
    // the docs are lying about one of them.
    record('order.placed', $this->mine);
    record('order.margin_note', $this->mine);
    record('order.placed', $this->theirs);

    Storyfeed::feeds([
        'closure' => fn (FeedBuilder $feed) => $feed->only(['order.placed', 'order.delivered'])->log(),
    ]);

    expect(classVerbsOf(CustomerFeed::make($this->mine)->get()->items()))
        ->toBe(classVerbsOf(Storyfeed::feed('closure')->context($this->mine)->get()->items()));
});

it('registers class feeds by key, by bare class, and alongside closures', function () {
    Storyfeed::feeds([
        'customers' => CustomerFeed::class,
        AdminFeed::class,
        'kitchen' => fn (FeedBuilder $feed) => $feed->only(['order.*']),
    ]);

    expect(Storyfeed::feedNames())->toBe(['customers', 'admin', 'kitchen']);
});

it('leaves plain builders unlocked — nothing changes for a feed with no class', function () {
    record('order.placed', $this->mine);
    record('order.placed', $this->theirs);

    $page = Storyfeed::feed()->context($this->mine)->context($this->theirs)->log()->get();

    expect($page->items())->toHaveCount(1);
});

it('rejects a registered class that is not a Feed', function () {
    expect(fn () => Storyfeed::feeds(['nope' => Customer::class]))
        ->toThrow(FeedMisconfigured::class, 'does not extend');
});

it('derives a name from the class, minus the Feed suffix', function () {
    expect(CustomerFeed::name())->toBe('customer')
        ->and(AdminFeed::name())->toBe('admin');
});

it('is a real class hierarchy, with no magic anywhere in it', function () {
    // Journal 006: __call verb magic was deleted at v0.4 on DX grounds, and
    // this layer is public API twice over. An unknown method must be an error.
    expect(method_exists(Feed::class, '__call'))->toBeFalse()
        ->and(method_exists(Feed::class, '__callStatic'))->toBeFalse()
        ->and(fn () => CustomerFeed::whateverYouLike($this->mine))->toThrow(Error::class);
});
