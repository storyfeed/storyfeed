<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Serialization\Reader;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('round-trips its own documents losslessly', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update]);

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)
        ->for(Customer::create(['name' => 'Acme Co.']))
        ->publishedAt(now()->subHour())
        ->publish();

    $document = app(ActivitySerializer::class)->activity(
        $activity->fresh(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']),
    );

    // Serialize → encode → decode → read: what a Storyfeed-aware consumer
    // does with our documents.
    $parsed = app(Reader::class)->activity(json_decode((string) json_encode($document), true));

    expect($parsed['uid'])->toBe($activity->uid)
        ->and($parsed['verb'])->toBe('confirm')
        ->and($parsed['type'])->toBe('Update')
        ->and($parsed['published_at']->timestamp)->toBe($activity->published_at->timestamp)
        ->and($parsed['actor']['name'])->toBe('Sally')
        ->and($parsed['object']['name'])->toBe('Delivery #TN-1')
        ->and($parsed['target']['name'])->toBe('Acme Co.');
});

it('reverse-maps the AS2 type through the registry when sf:verb is absent', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update], merge: false);

    // A foreign document: mapped type, no extension property.
    $verb = app(Reader::class)->verb(['type' => 'Update']);

    expect($verb)->toBe('confirm');
});
