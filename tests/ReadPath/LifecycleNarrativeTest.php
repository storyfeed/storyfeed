<?php

use Illuminate\Support\Facades\Auth;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * Evidence for the adoption question "one status verb + data:from/to +
 * publishAndReplace(), or a verb per transition?" — asked by a real app with a
 * seven-state, CUSTOMER-FACING order lifecycle.
 *
 * The crux is not authoring taste, it is what replace does to history. These
 * tests are the answer, and they are here so a future refactor cannot quietly
 * change it.
 */
$states = ['placed', 'confirmed', 'cooking', 'ready', 'out_for_delivery', 'delivered', 'paid'];

it('destroys the timeline when one verb is published with replace', function () use ($states) {
    $order = Delivery::create(['tracking_number' => 'ORDER-1']);

    foreach (array_slice($states, 1) as $i => $to) {
        Storyfeed::activity('updateStatus', $order)
            ->data(['from' => $states[$i], 'to' => $to])
            ->publishAndReplace();
    }

    $items = $order->storyfeed()->log()->get()->items();

    // Six transitions were recorded. One survives.
    expect($items)->toHaveCount(1)
        ->and(Activity::query()->object($order)->verb('updateStatus')->count())->toBe(1);

    // And the survivor knows only where it currently is — "cooking" is not
    // merely ungrouped, it is DELETED. No read mode can bring it back.
    $data = Activity::query()->object($order)->verb('updateStatus')->sole()->data;
    expect($data['to'])->toBe('paid')->and($data['from'])->toBe('delivered');
});

it('keeps the timeline when the same one verb is published without replace', function () use ($states) {
    $order = Delivery::create(['tracking_number' => 'ORDER-2']);

    foreach (array_slice($states, 1) as $i => $to) {
        Storyfeed::activity('updateStatus', $order)
            ->data(['from' => $states[$i], 'to' => $to])
            ->publish();
    }

    expect($order->storyfeed()->log()->get()->items())->toHaveCount(6);
});

it('keeps the timeline with a verb per transition, replace or not', function () use ($states) {
    $order = Delivery::create(['tracking_number' => 'ORDER-3']);

    foreach (array_slice($states, 1) as $to) {
        // Replace matches on object + verb, so distinct verbs never collide:
        // each transition is idempotent against ITSELF and nothing else.
        Storyfeed::activity('order.'.$to, $order)->publishAndReplace();
    }

    expect($order->storyfeed()->log()->get()->items())->toHaveCount(6);
});

it('collapses a re-fired transition without touching its neighbours', function () {
    $order = Delivery::create(['tracking_number' => 'ORDER-4']);

    Storyfeed::activity('order.confirmed', $order)->publishAndReplace();
    Storyfeed::activity('order.cooking', $order)->publishAndReplace();
    Storyfeed::activity('order.cooking', $order)->publishAndReplace(); // double-click, retried job
    Storyfeed::activity('order.ready', $order)->publishAndReplace();

    expect($order->storyfeed()->log()->get()->items())->toHaveCount(3);
});

/**
 * Evidence for "does a guest on a signed link have a feed?" — nothing in the
 * read path touches the guard. The only Auth call in src/ is the write path's
 * actor resolver (StoryfeedManager::resolveActor), and it is not reached here.
 */
it('reads an order timeline with no authenticated user', function () {
    $cook = User::create(['name' => 'Nayani', 'email' => 'cook@example.com']);
    $customer = Customer::create(['name' => 'Guest Customer']);
    $order = Delivery::create(['tracking_number' => 'ORDER-5']);

    Storyfeed::activity()->actor($customer)->verb('order.placed', $order)->context($order)->publish();
    Storyfeed::activity()->actor($cook)->verb('order.cooking', $order)->to($customer)->context($order)->publish();

    Auth::logout();

    expect(Auth::check())->toBeFalse()
        ->and(Storyfeed::feed()->context($order)->log()->get()->items())->toHaveCount(2)
        ->and($order->storyfeed()->log()->get()->items())->toHaveCount(2);
});

it('misses the placement activity when context() is scoped but never set', function () {
    $customer = Customer::create(['name' => 'Guest Customer']);
    $order = Delivery::create(['tracking_number' => 'ORDER-6']);

    // The natural authoring of "an order was placed": the order is the OBJECT,
    // and there is no container to be the context yet.
    Storyfeed::activity()->actor($customer)->verb('order.placed', $order)->publish();
    Storyfeed::activity()->verb('order.cooking', $order)->context($order)->publish();

    expect(Storyfeed::feed()->context($order)->log()->get()->items())->toHaveCount(1)
        ->and($order->storyfeed()->log()->get()->items())->toHaveCount(2);
});

/**
 * A verb allowlist is a QUERY FILTER, expressible today, and it composes with
 * everything else — it is not the read path hiding an activity.
 */
it('restricts a customer timeline to an allowlist of verbs', function () {
    $order = Delivery::create(['tracking_number' => 'ORDER-7']);

    Storyfeed::activity('order.confirmed', $order)->publish();
    Storyfeed::activity('order.margin_reviewed', $order)->publish();
    Storyfeed::activity('order.delivered', $order)->publish();

    $public = ['order.confirmed', 'order.delivered'];

    $items = $order->storyfeed()
        ->query(fn ($q) => $q->whereIn('verb', $public))
        ->log()->get()->items();

    expect($items)->toHaveCount(2);
});
