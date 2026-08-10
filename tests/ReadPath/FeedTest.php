<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('returns newest-first payload items', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity()->create($delivery)->publishedAt(now()->subHour())->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $payload = Storyfeed::feed()->get()->toArray();

    expect($payload['payload_version'])->toBe(1)
        ->and($payload['items'])->toHaveCount(2)
        ->and($payload['items'][0]['verb'])->toBe('ping');
});

it('nests same-repeat-hash activities into a group node', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($user)->upload(Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(3)
        ->and($items[0]['children'])->toHaveCount(3)
        ->and($items[0]['exemplars']['actors'][0]['label'])->toBe('Sally');
});

it('keeps singletons as activity nodes', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity()->confirm($delivery)->publish();

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('activity');
});

it('scopes the feed by actor', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();
    Storyfeed::activity()->actor($bob)->verb('ping')->publish();

    $items = Storyfeed::feed()->actor($sally)->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['actor']['label'])->toBe('Sally');
});

it('scopes the feed by context', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);

    Storyfeed::activity()->verb('ping')->context($customer)->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $items = Storyfeed::feed()->context($customer)->get()->toArray()['items'];

    expect($items)->toHaveCount(1);
});

it('caps the page to the top-N groups', function () {
    foreach (range(1, 5) as $i) {
        $user = User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]);
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt(now()->subMinutes($i))->publish();
    }

    $payload = Storyfeed::feed()->limit(2)->get()->toArray();

    // INTERIM read strategy: the cursor pages within the selected group
    // set only — deep pagination past top-N is the documented limitation
    // the curated read model solves (docs/grouping.md).
    expect($payload['items'])->toHaveCount(2);
});

it('cursors through raw rows when a page overflows', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    // One group, 25 member rows: exceeds the limit(2)*10 over-fetch window.
    foreach (range(1, 25) as $i) {
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt(now()->subMinutes($i))->publish();
    }

    $payload = Storyfeed::feed()->limit(2)->get()->toArray();

    expect($payload['next_cursor'])->toBeString();

    $page2 = Storyfeed::feed()->limit(2)->cursor($payload['next_cursor'])->get()->toArray();

    expect(collect($page2['items'])->sum(fn ($i) => $i['kind'] === 'group' ? $i['count'] : 1))->toBe(5);
});

it('excludes unpublished (future) activities', function () {
    Storyfeed::activity()->verb('ping')->publishedAt(now()->addDay())->publish();

    expect(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(0);
});
