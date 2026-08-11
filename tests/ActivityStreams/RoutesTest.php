<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedServiceProvider;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function enableRoutes(): void
{
    config()->set('storyfeed.routes.enabled', true);

    // Config is read at boot; re-register the routes for this test.
    app(StoryfeedServiceProvider::class, ['app' => app()])->packageBooted();
}

it('registers no routes by default', function () {
    Storyfeed::activity()->verb('ping')->publish();

    $this->get('/storyfeed/feed', ['Accept' => 'application/activity+json'])
        ->assertNotFound();
});

it('serves a single activity document, content-negotiated', function () {
    enableRoutes();

    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->get("/storyfeed/activities/{$activity->uid}", ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/activity+json')
        ->assertJsonPath('sf:verb', 'confirm')
        ->assertJsonPath('@context.0', 'https://www.w3.org/ns/activitystreams');
});

it('refuses non-AS2 accept headers', function () {
    enableRoutes();

    $activity = Storyfeed::activity()->verb('ping')->publish();

    $this->get("/storyfeed/activities/{$activity->uid}", ['Accept' => 'text/html'])
        ->assertStatus(406);
});

it('hides unpublished activities', function () {
    enableRoutes();

    $activity = Storyfeed::activity()->verb('ping')->publishedAt(now()->addDay())->publish();

    $this->get("/storyfeed/activities/{$activity->uid}", ['Accept' => 'application/activity+json'])
        ->assertNotFound();
});

it('serves the feed as an OrderedCollection and pages with the opaque cursor', function () {
    enableRoutes();

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 5) as $i) {
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt(now()->subMinutes($i))->publish();
    }

    $first = $this->get('/storyfeed/feed?limit=2', ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertJsonPath('type', 'OrderedCollection')
        ->assertJsonCount(2, 'orderedItems')
        ->json();

    expect($first)->toHaveKey('next')
        ->and($first)->not->toHaveKey('totalItems');

    parse_str((string) parse_url($first['next'], PHP_URL_QUERY), $params);

    $second = $this->get('/storyfeed/feed?limit=2&cursor='.$params['cursor'], ['Accept' => 'application/activity+json'])
        ->assertOk()
        ->assertJsonPath('type', 'OrderedCollectionPage')
        ->assertJsonCount(2, 'orderedItems')
        ->json();

    expect($second['partOf'])->toEndWith('/storyfeed/feed');

    $firstIds = array_column($first['orderedItems'], 'id');
    $secondIds = array_column($second['orderedItems'], 'id');

    expect(array_intersect($firstIds, $secondIds))->toBe([]);
});
