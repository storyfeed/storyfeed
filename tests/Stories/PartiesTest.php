<?php

use Illuminate\Http\Request;
use Storyfeed\Exceptions\UndeclaredParty;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;
use Storyfeed\Support\IgnoredParties;
use Storyfeed\Tests\Fixtures\Stories\RefundStory;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Storyfeed::parties(): the names an actor may take. Undeclared throws in
 * development and is ignored in production, where the activity keeps the
 * actor it would otherwise have had, and the doctor names it.
 */

beforeEach(function () {
    $this->delivery = Delivery::create(['tracking_number' => 'T1']);
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    Story::verb('refund')->headline(':actor refunded :object');
});

it('guards nothing until parties are declared', function () {
    $activity = Storyfeed::actor('Anything', fn () => Storyfeed::activity('refund', $this->delivery)->publish());

    expect($activity->actor->name)->toBe('Anything')
        ->and(Storyfeed::declaredParties())->toBeNull();
});

it('admits a declared name, matched as party keys are', function () {
    Storyfeed::parties(['Stripe', 'Paddle']);

    $activity = Storyfeed::actor('stripe', fn () => Storyfeed::activity('refund', $this->delivery)->publish());

    expect($activity->actor->key)->toBe('stripe')
        ->and(Storyfeed::declaredParties())->toBe(['Stripe', 'Paddle']);
});

it('throws on an undeclared name in Storyfeed::actor(), with or without a callback', function () {
    Storyfeed::parties(['Stripe']);
    $message = 'Storyfeed::actor() names the party [Vendor X], which Storyfeed::parties() does not declare (it declares Stripe)';

    expect(fn () => Storyfeed::actor('Vendor X', fn () => null))->toThrow(UndeclaredParty::class, $message)
        ->and(fn () => Storyfeed::actor('Vendor X'))->toThrow(UndeclaredParty::class, $message);
});

it('throws on an undeclared name an action chooses, naming the action', function () {
    Storyfeed::parties(['Stripe']);
    Story::resource('delivery', RefundStory::class)->only('refund');
    app()->instance('request', Request::create('/', 'POST', ['provider' => 'Vendor X']));

    Storyfeed::activity('refund', $this->delivery)->publish();
})->throws(UndeclaredParty::class, RefundStory::class.'@refund names the party [Vendor X]');

it('ignores an undeclared name in production: the activity keeps the actor it would have had', function () {
    config(['storyfeed.parties.strict' => false]);
    Storyfeed::parties(['Stripe']);
    Story::resource('delivery', RefundStory::class)->only('refund');
    $this->actingAs($this->ines);

    $scoped = Storyfeed::actor('Vendor X', fn () => Storyfeed::activity('refund', $this->delivery)->publish());
    $built = Storyfeed::actor('Vendor Y')->verb('refund', $this->delivery)->publish();

    app()->instance('request', Request::create('/', 'POST', ['provider' => 'Vendor Z']));
    $action = Storyfeed::activity('refund', $this->delivery)->publish();

    expect($scoped->actor->is($this->ines))->toBeTrue()
        ->and($built->actor->is($this->ines))->toBeTrue()
        ->and($action->actor->is($this->ines))->toBeTrue()
        ->and(Party::query()->pluck('name')->all())->toBe([])
        ->and(IgnoredParties::all())->toBe(['Vendor X', 'Vendor Y', 'Vendor Z']);
});

it('never makes an ignored name anonymous', function () {
    config(['storyfeed.parties.strict' => false]);
    Storyfeed::parties(['Stripe']);

    $activity = Storyfeed::actor('Vendor X', fn () => Storyfeed::activity('refund', $this->delivery)->publish());

    // No one signed in and no fallback: anonymous, as it would have been.
    // With a fallback party it is the fallback.
    config(['storyfeed.parties.fallback' => 'Stripe']);
    $fallback = Storyfeed::actor('Vendor X', fn () => Storyfeed::activity('refund', $this->delivery)->publish());

    expect($activity->actor_type)->toBeNull()
        ->and($fallback->actor->name)->toBe('Stripe');
});

it('keeps at most a bounded number of ignored names', function () {
    config(['storyfeed.parties.strict' => false]);
    Storyfeed::parties(['Stripe']);

    foreach (range(1, IgnoredParties::LIMIT + 5) as $i) {
        Storyfeed::actor("Vendor {$i}", fn () => null);
    }

    expect(IgnoredParties::all())->toHaveCount(IgnoredParties::LIMIT);
});

it('names what production ignored in the doctor', function () {
    config(['storyfeed.parties.strict' => false]);
    Storyfeed::parties(['Stripe']);
    Storyfeed::actor('Vendor X', fn () => null);

    $finding = Storyfeed::doctor(['parties'])->withCode('parties.ignored')->first();

    expect($finding)->not->toBeNull()
        ->and($finding->subject)->toBe(['name' => 'Vendor X']);
});

it('warns about a fixed actor the list does not declare', function () {
    Storyfeed::parties(['Stripe']);
    Story::verb('import')->headline(':actor imported :object')->actor('Legacy');

    $finding = Storyfeed::doctor(['parties'])->withCode('parties.undeclared_actor')->first();

    expect($finding->subject)->toBe(['key' => '*.import', 'name' => 'Legacy']);
});

it('says when parties are in use and none are declared', function () {
    Storyfeed::actor('Stripe', fn () => Storyfeed::activity('refund', $this->delivery)->publish());

    expect(Storyfeed::doctor(['parties'])->has('parties.undeclared_list'))->toBeTrue();

    Storyfeed::parties(['Stripe']);

    expect(Storyfeed::doctor(['parties'])->has('parties.undeclared_list'))->toBeFalse();
});
