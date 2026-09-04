<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasFeedMedia;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Support\LinkResolver;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * The resolver seam (issue #2): feedMedia(FeedContext) is preferred over
 * toFeedLink(array), and the older contract keeps working unchanged.
 */

beforeEach(function () {
    Customer::$feedLinkCalls = 0;
    Customer::$lastContext = null;
});

it('prefers feedMedia() over toFeedLink() when a model implements both', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['url'])->toBe("/customers/{$customer->id}")
        ->and(Customer::$feedLinkCalls)->toBe(0);
});

it('hands feedMedia() the snapshot as a context, not a bare array', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();
    Storyfeed::feed()->get()->toArray();

    $context = Customer::$lastContext;

    expect($context)->toBeInstanceOf(FeedContext::class)
        ->and($context->type())->toBe('customer')
        ->and($context->id())->toBe($customer->id)
        ->and($context->label())->toBe('Acme')
        ->and($context->data())->toBe(['id' => $customer->id, 'name' => 'Acme'])
        ->and($context->data('name'))->toBe('Acme');
});

it('degrades a missing context value to the default instead of warning', function () {
    $context = new FeedContext(type: 'customer', data: ['id' => 1]);

    expect($context->data('nope'))->toBeNull()
        ->and($context->data('nope', 'fallback'))->toBe('fallback')
        ->and($context->id())->toBeNull()
        ->and($context->label())->toBeNull();
});

it('still answers through toFeedLink() for models that have not moved', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1', 'status' => 'draft']);

    Storyfeed::activity('confirm', $delivery)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['url'])->toBe("/deliveries/{$delivery->id}")
        ->and($item['object']['attributes'])->toMatchArray(['data-status' => 'draft'])
        ->and($item['object']['modal'])->toBeFalse();
});

it('lifts a FeedLink into FeedMedia without losing a slot', function () {
    $media = FeedMedia::fromLink(FeedLink::modal('/x', 'Label', ['target' => '_blank']));

    expect($media)->toBeInstanceOf(FeedMedia::class)
        ->and($media->url)->toBe('/x')
        ->and($media->label)->toBe('Label')
        ->and($media->attributes)->toBe(['target' => '_blank'])
        ->and($media->modal)->toBeTrue();
});

it('lets feedMedia() override the cached label and hint a modal', function () {
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::modal('/m/'.$context->id(), 'Fresh '.$context->label());
        }
    };

    Relation::morphMap(['fresh' => $model::class]);

    $media = LinkResolver::resolve(new FeedContext(type: 'fresh', id: 7, label: 'Acme'));

    expect($media?->url)->toBe('/m/7')
        ->and($media?->label)->toBe('Fresh Acme')
        ->and($media?->modal)->toBeTrue();
});

it('reports a throwing feedMedia() and degrades to null', function () {
    Exceptions::fake();

    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            throw new RuntimeException('boom');
        }
    };

    Relation::morphMap(['boom' => $model::class]);

    $media = LinkResolver::resolve(new FeedContext(type: 'boom', id: 1, data: ['id' => 1]));

    expect($media)->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('returns null for an alias whose class implements neither contract', function () {
    Relation::morphMap(['plain' => Activity::class]);

    expect(LinkResolver::resolve(new FeedContext(type: 'plain', data: ['id' => 1])))->toBeNull()
        ->and(LinkResolver::resolve(new FeedContext(type: 'unknown-alias')))->toBeNull();
});

it('never calls feedMedia() for un-snapshotted entities', function () {
    Activity::query()->create([
        'verb' => 'onboard',
        'object_type' => 'customer',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect(Customer::$lastContext)->toBeNull()
        ->and($item['object']['url'])->toBeNull();
});

it('serializes the feedMedia() url into the AS2.0 document', function () {
    $customer = Customer::create(['name' => 'Acme']);

    $activity = Storyfeed::activity('onboard', $customer)->publish();

    $document = serialize_one($activity);

    expect($document['object']['url'])->toEndWith("/customers/{$customer->id}");
});

it('keeps the contracts optional and interface-shaped', function () {
    expect(Customer::class)->toImplement(HasFeedMedia::class)
        ->and(Delivery::class)->toImplement(Feedable::class)
        ->and(Delivery::class)->not->toImplement(HasFeedMedia::class);
});
