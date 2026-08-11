<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('returns newest-first payload items', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('create', $delivery)->publishedAt(now()->subHour())->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $payload = Storyfeed::feed()->get()->toArray();

    expect($payload['payload_version'])->toBe(1)
        ->and($payload['items'])->toHaveCount(2)
        ->and($payload['items'][0]['verb'])->toBe('ping');
});

it('nests same-repeat-hash activities into a group node', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($user)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
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

    Storyfeed::activity('confirm', $delivery)->publish();

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

it('pages past the first screen of groups', function () {
    foreach (range(1, 5) as $i) {
        $user = User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]);

        foreach (range(1, 2) as $n) {
            Storyfeed::activity()->actor($user)->verb('ping')
                ->publishedAt(now()->subMinutes($i * 10 + $n))
                ->publish();
        }
    }

    $seen = [];
    $cursor = null;

    foreach (range(1, 3) as $page) {
        $payload = Storyfeed::feed()->limit(2)->cursor($cursor)->get()->toArray();
        $cursor = $payload['next_cursor'];

        $seen = [...$seen, ...collect($payload['items'])->pluck('id')->all()];
    }

    // Five groups, two per page: the fifth is reachable and no group is
    // emitted twice — the deep-pagination cap is gone.
    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5)
        ->and($cursor)->toBeNull();
});

it('emits one node with a true count and capped children for a large group', function () {
    config()->set('storyfeed.grouping.children_limit', 10);

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 25) as $i) {
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt(now()->subMinutes($i))->publish();
    }

    $payload = Storyfeed::feed()->limit(2)->get()->toArray();

    expect($payload['items'])->toHaveCount(1)
        ->and($payload['items'][0]['kind'])->toBe('group')
        ->and($payload['items'][0]['count'])->toBe(25)
        ->and($payload['items'][0]['children'])->toHaveCount(10)
        ->and($payload['items'][0]['children_truncated'])->toBeTrue()
        ->and($payload['next_cursor'])->toBeNull();
});

it('orders deterministically when groups share a published_at', function () {
    $at = now()->subHour();

    foreach (range(1, 6) as $i) {
        $user = User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]);
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt($at)->publish();
    }

    $seen = [];
    $cursor = null;

    do {
        $payload = Storyfeed::feed()->limit(2)->cursor($cursor)->get()->toArray();
        $cursor = $payload['next_cursor'];
        $seen = [...$seen, ...collect($payload['items'])->pluck('id')->all()];
    } while ($cursor !== null);

    expect($seen)->toHaveCount(6)
        ->and(array_unique($seen))->toHaveCount(6);
});

it('excludes unpublished (future) activities', function () {
    Storyfeed::activity()->verb('ping')->publishedAt(now()->addDay())->publish();

    expect(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(0);
});
