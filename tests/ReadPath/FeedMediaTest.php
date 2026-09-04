<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasFeedMedia;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Support\LinkResolver;
use Workbench\App\Feeds\AdminFeed;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

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

it('hands the same entity id to the resolver from both surfaces', function () {
    // The presenter takes the id from the activity's role column; the
    // serializer takes it from Snapshot::model_id. Two sources for one fact
    // is a drift waiting to happen, so pin that they agree — and agree with
    // the row the snapshot points at.
    $customer = Customer::create(['name' => 'Acme']);

    $activity = Storyfeed::activity('onboard', $customer)->publish();

    Storyfeed::feed()->get()->toArray();
    $presented = Customer::$lastContext;

    Customer::$lastContext = null;
    serialize_one($activity);
    $serialized = Customer::$lastContext;

    expect($presented?->id())->toBe($customer->id)
        ->and($serialized?->id())->toBe($presented?->id())
        ->and($serialized?->type())->toBe($presented?->type())
        ->and($activity->fresh()->cachedObject?->model_id)->toBe($presented?->id());
});

it('keeps the contracts optional and interface-shaped', function () {
    expect(Customer::class)->toImplement(HasFeedMedia::class)
        ->and(Delivery::class)->toImplement(Feedable::class)
        ->and(Delivery::class)->not->toImplement(HasFeedMedia::class);
});

/*
 * Surface identity (issue #3): FeedContext::feed() is the registered name of
 * the feed the page was read through — declared by the registry, never
 * sniffed from a request — so one snapshot can link differently per surface.
 */

it('tells the resolver which named feed the page was read through', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->log()]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed('kitchen')->get()->toArray()['items'][0];

    expect(Customer::$lastContext?->feed())->toBe('kitchen')
        ->and($item['object']['url'])->toBe("/kitchen/customers/{$customer->id}");
});

it('reports the class-derived name for a feed entered through its constructor', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('order.placed', $customer)->context($customer)->publish();

    CustomerFeed::make($customer)->get()->toArray();

    expect(Customer::$lastContext?->feed())->toBe('customer');
});

it('reports the class-derived name for a feed reached by class-string', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    Storyfeed::feed(AdminFeed::class)->get()->toArray();

    expect(Customer::$lastContext?->feed())->toBe('admin');
});

it('reports the registered key, not the class name, when a class feed is registered under one', function () {
    // The identity is the name the feed was ENTERED by. Registering a class
    // under a different key and then reading it by that key reports the key;
    // the docblock on FeedContext::feed() says to compare against
    // Feed::name() when a rename must not break a resolver.
    Storyfeed::feeds(['staff' => AdminFeed::class]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    Storyfeed::feed('staff')->get()->toArray();

    expect(Customer::$lastContext?->feed())->toBe('staff')
        ->and(AdminFeed::name())->toBe('admin');
});

it('reports no feed for an ad-hoc builder rather than inventing a name', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->log()]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect(Customer::$lastContext?->feed())->toBeNull()
        ->and($item['object']['url'])->toBe("/customers/{$customer->id}");

    Customer::$lastContext = null;
    $customer->storyfeed()->get()->toArray();

    expect(Customer::$lastContext)->not->toBeNull()
        ->and(Customer::$lastContext?->feed())->toBeNull()
        ->and((new FeedBuilder)->declaredFeed())->toBeNull()
        ->and(Storyfeed::feed('kitchen')->declaredFeed())->toBe('kitchen')
        ->and((new FeedContext(type: 'customer'))->feed())->toBeNull();
});

it('reports no feed to the AS2.0 serializer, even when a named feed exists', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->log()]);

    $customer = Customer::create(['name' => 'Acme']);

    $activity = Storyfeed::activity('onboard', $customer)->publish();

    $document = serialize_one($activity);

    expect(Customer::$lastContext?->feed())->toBeNull()
        ->and($document['object']['url'])->toEndWith("/customers/{$customer->id}")
        ->and($document['object']['url'])->not->toContain('/kitchen/');
});

it('carries the feed into every entity of a group node, exemplars and children alike', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->live()]);

    $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($ines)->verb('onboard', $customer)->publish();
    }

    $item = Storyfeed::feed('kitchen')->get()->toArray()['items'][0];

    expect($item['kind'])->toBe('group')
        ->and($item['exemplars']['objects'][0]['url'])->toBe("/kitchen/customers/{$customer->id}")
        ->and($item['children'][0]['object']['url'])->toBe("/kitchen/customers/{$customer->id}");
});

it('does not leak one page\'s feed into the next through a shared presenter', function () {
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->log()]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $kitchen = Storyfeed::feed('kitchen')->get()->toArray()['items'][0];
    $plain = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($kitchen['object']['url'])->toBe("/kitchen/customers/{$customer->id}")
        ->and($plain['object']['url'])->toBe("/customers/{$customer->id}");
});
