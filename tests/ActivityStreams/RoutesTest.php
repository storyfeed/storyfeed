<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedServiceProvider;
use Workbench\App\Models\Delivery;

function enableRoutes(): void
{
    config()->set('storyfeed.routes.enabled', true);

    // Config is read at boot; re-register the routes for this test.
    app(StoryfeedServiceProvider::class, ['app' => app()])->packageBooted();
}

it('registers no routes by default', function () {
    $activity = Storyfeed::activity()->verb('ping')->publish();

    $this->get("/storyfeed/activities/{$activity->uid}", ['Accept' => 'application/activity+json'])
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

it('serves no collection route, even with routes enabled', function () {
    // Removed at v0.8.0-alpha.2: it was every published activity in the system,
    // unscoped and with no verb allowlist. The OrderedCollection SHAPE is still
    // supported and still covered — by CollectionSerializerTest, against the
    // serializer directly, because there is no longer an endpoint to drive it
    // through. It returns when a named feed can back it.
    enableRoutes();

    Storyfeed::activity()->verb('ping')->publish();

    $this->get('/storyfeed/feed', ['Accept' => 'application/activity+json'])
        ->assertNotFound();
});
