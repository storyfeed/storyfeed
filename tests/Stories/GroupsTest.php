<?php

use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Middleware\Batch;
use Storyfeed\Stories\PendingGroup;
use Storyfeed\Tests\Fixtures\Middleware\Trace;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasSorted;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Group attributes chain, as a RouteRegistrar's do: Story::for(),
 * middleware(), withoutMiddleware(), as() / name() and the role
 * constraints, in any order, onto one PendingGroup that ends in group().
 * Nested groups merge as RouteGroup::merge() merges route groups.
 */

beforeEach(function () {
    Story::aliasMiddleware('trace', Trace::class);
});

it('chains every attribute in any order onto one group', function () {
    $group = Story::middleware('trace:audit')->as('billing.')->for(Delivery::class)->whereActor(User::class);

    $group->group(function () {
        Story::verb('refund')->name('delivery.refund');
    });

    expect($group)->toBeInstanceOf(PendingGroup::class)
        ->and(Story::for(Delivery::class))->toBeInstanceOf(PendingGroup::class)
        ->and(Story::as('x.'))->toBeInstanceOf(PendingGroup::class)
        ->and(Story::withoutMiddleware('batch'))->toBeInstanceOf(PendingGroup::class)
        ->and(Story::whereActor(User::class))->toBeInstanceOf(PendingGroup::class)
        ->and(Storyfeed::storyNames())->toBe(['billing.delivery.refund' => 'delivery.refund'])
        ->and(Storyfeed::middleware('delivery', 'refund'))->toBe([Batch::class, Trace::class.':audit'])
        ->and(Storyfeed::wheres('delivery', 'refund'))->toBe(['actor' => ['user']]);
});

it('merges nested groups as RouteGroup::merge() does: names concatenate, middleware appends', function () {
    Story::as('billing.')->middleware('trace:outer')->group(function () {
        Story::for(Delivery::class)->as('ops.')->middleware('trace:inner')->group(function () {
            Story::verb('ship')->name('delivery.ship')->middleware('trace:own');
        });

        Story::verb('wave')->name('wave');
    });

    Story::verb('return')->name('return');

    expect(Storyfeed::storyNames())->toBe([
        'billing.ops.delivery.ship' => 'delivery.ship',
        'billing.wave' => '*.wave',
        'return' => '*.return',
    ])
        ->and(Storyfeed::middleware('delivery', 'ship'))->toBe([Batch::class, Trace::class.':outer', Trace::class.':inner', Trace::class.':own'])
        ->and(Storyfeed::middleware(null, 'wave'))->toBe([Batch::class, Trace::class.':outer'])
        ->and(Storyfeed::middleware(null, 'return'))->toBe([Batch::class]);
});

it('keeps the enclosing group\'s types for a group that names none', function () {
    Story::for(Delivery::class)->group(function () {
        Story::middleware('trace:inner')->group(function () {
            Story::verb('ship')->headline(':actor shipped :object');
        });
    });

    expect(Storyfeed::registeredGrammar())->toHaveKey('delivery.ship')
        ->and(Storyfeed::middleware('delivery', 'ship'))->toBe([Batch::class, Trace::class.':inner']);
});

it('takes middleware out of every definition in a withoutMiddleware() group', function () {
    Story::withoutMiddleware('batch')->middleware('trace:quiet')->group(function () {
        Story::for(Delivery::class)->verb('ship');
        Story::resource(Customer::class)->only('create');
        Story::for(Delivery::class)->verb('sort', DeliveryWasSorted::class);
    });

    expect(Storyfeed::middleware('delivery', 'ship'))->toBe([Trace::class.':quiet'])
        ->and(Storyfeed::middleware('customer', 'create'))->toBe([Trace::class.':quiet'])
        ->and(Storyfeed::middleware('delivery', 'sort'))->toBe([Trace::class.':quiet', Trace::class.':class', Batch::class.':5 minutes']);
});

it('defines through the chain without group(), as Route::middleware()->get() does', function () {
    Story::middleware('trace:direct')->as('ops.')->for(Delivery::class)->verb('ship')->name('delivery.ship');
    Story::middleware('trace:resource')->resource(Customer::class)->only('create');

    expect(Storyfeed::storyNames())->toMatchArray(['ops.delivery.ship' => 'delivery.ship', 'customer.create' => 'customer.create'])
        ->and(Storyfeed::middleware('delivery', 'ship'))->toBe([Batch::class, Trace::class.':direct'])
        ->and(Storyfeed::middleware('customer', 'create'))->toBe([Batch::class, Trace::class.':resource']);
});

it('applies a group once when its own closure defines through it', function () {
    Story::for(Delivery::class)->as('ops.')->middleware('trace:once')->group(function (PendingGroup $delivery) {
        $delivery->verb('ship')->name('delivery.ship');
    });

    expect(Storyfeed::storyNames())->toBe(['ops.delivery.ship' => 'delivery.ship'])
        ->and(Storyfeed::middleware('delivery', 'ship'))->toBe([Batch::class, Trace::class.':once']);
});

it('refuses a type scope inside a type scope, however it is reached', function () {
    $outside = Story::for(Customer::class);

    expect(fn () => Story::for(Delivery::class)->group(fn () => Story::for(Customer::class)))
        ->toThrow(StoryMisconfigured::class, 'scopes do not nest')
        ->and(fn () => Story::for(Delivery::class)->group(fn () => Story::middleware('trace:x')->for(Customer::class)))
        ->toThrow(StoryMisconfigured::class, 'scopes do not nest')
        ->and(fn () => Story::for(Delivery::class)->group(fn () => $outside->group(fn () => null)))
        ->toThrow(StoryMisconfigured::class, 'scopes do not nest');
});

it('pops every attribute when a group closure throws', function () {
    try {
        Story::for(Delivery::class)->as('ops.')->middleware('trace:x')->whereActor(User::class)->group(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    Story::verb('confirm')->name('confirm');

    expect(Storyfeed::storyNames())->toBe(['confirm' => '*.confirm'])
        ->and(Storyfeed::middleware(null, 'confirm'))->toBe([Batch::class])
        ->and(Storyfeed::wheres(null, 'confirm'))->toBe([]);
});
