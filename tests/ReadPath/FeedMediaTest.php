<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\LinkResolver;
use Workbench\App\Feeds\AdminFeed;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The resolver contract (issues #2 and #6): Feedable::feedMedia(FeedContext)
 * is the one resolver. HasFeedMedia, toFeedLink(array) and FeedLink were
 * folded away on 2026-09-05 (journal 057), and the trait supplies the null
 * default so the fold does not redline a model that has nowhere to point.
 */

beforeEach(function () {
    Customer::$lastContext = null;
});

it('has one contract: feedMedia() lives on Feedable and the interim pieces are gone', function () {
    expect(method_exists(Feedable::class, 'feedMedia'))->toBeTrue()
        ->and(method_exists(Feedable::class, 'toFeedLink'))->toBeFalse()
        ->and(interface_exists('Storyfeed\\Contracts\\HasFeedMedia'))->toBeFalse()
        ->and(class_exists('Storyfeed\\FeedLink'))->toBeFalse()
        ->and(method_exists(FeedMedia::class, 'fromLink'))->toBeFalse();
});

it('answers through Feedable::feedMedia() for every model on the contract', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['url'])->toBe("/customers/{$customer->id}")
        ->and(Customer::$lastContext)->toBeInstanceOf(FeedContext::class);
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

it('carries url, attributes and the modal hint from a migrated resolver without losing a slot', function () {
    // Delivery was the last workbench model on toFeedLink(); the fold moved
    // it. Everything a FeedLink used to carry still arrives on the node.
    $delivery = Delivery::create(['tracking_number' => 'TN-1', 'status' => 'draft']);

    Storyfeed::activity('confirm', $delivery)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['url'])->toBe("/deliveries/{$delivery->id}")
        ->and($item['object']['attributes'])->toMatchArray(['data-status' => 'draft'])
        ->and($item['object']['modal'])->toBeFalse();
});

it('compiles a bare Feedable with only toFeed() written, and links nothing', function () {
    // The DX promise of the fold: `implements Feedable` + `use InteractsWithFeed`
    // is green on first save, because the trait answers feedMedia() with null.
    $model = new class extends Model implements Feedable
    {
        use InteractsWithFeed;

        protected $table = 'customers';

        protected $guarded = [];

        public function toFeed(): FeedEntity
        {
            return FeedEntity::make(label: 'Bare');
        }
    };

    Relation::morphMap(['bare' => $model::class]);

    expect($model::feedMedia(new FeedContext(type: 'bare', id: 1)))->toBeNull()
        ->and(LinkResolver::resolve(new FeedContext(type: 'bare', id: 1, data: ['id' => 1])))->toBeNull();

    $bare = $model::create(['name' => 'Bare']);

    Storyfeed::activity('onboard', $bare)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['label'])->toBe('Bare')
        ->and($item['object']['url'])->toBeNull()
        ->and($item['object']['media'])->toBeNull();
});

it('defaults feedMedia() in the trait and deliberately not toFeed()', function () {
    // A missing link is a state; a missing label is a defect. The trait
    // satisfies only the method where "nothing" is a real answer.
    $trait = new ReflectionClass(InteractsWithFeed::class);

    expect($trait->hasMethod('feedMedia'))->toBeTrue()
        ->and($trait->getMethod('feedMedia')->isStatic())->toBeTrue()
        ->and($trait->hasMethod('toFeed'))->toBeFalse();
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

it('returns null for an alias whose class is not Feedable, and for an alias that resolves to nothing', function () {
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

it('puts every model on the same contract, package-owned ones included', function () {
    foreach ([Customer::class, Delivery::class, User::class, Party::class] as $class) {
        expect($class)->toImplement(Feedable::class)
            ->and(method_exists($class, 'feedMedia'))->toBeTrue();
    }

    // Party has no canonical URL and does not use the trait (it keeps its
    // own saved hook and no delete cascade), so it answers null itself.
    expect(Party::feedMedia(new FeedContext(type: 'storyfeed.party', id: 1)))->toBeNull();
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
    // Registered under no key, so the derived name is the only identity the
    // class has — canonical because it is the only one.
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
    Storyfeed::feeds(['staff' => AdminFeed::class]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    Storyfeed::feed('staff')->get()->toArray();

    expect(Customer::$lastContext?->feed())->toBe('staff');
});

it('reports one identity whichever door a registered class feed is entered by', function () {
    // The trapdoor journal 054 escalated: a resolver matching on 'kitchen'
    // was right for Storyfeed::feed('kitchen') and silently wrong for
    // CustomerFeed::make(), because make() reported the derived 'customer'.
    // The registered key wins on every door, and Feed::name() returns it,
    // so a `CustomerFeed::name() => …` arm survives a key rename too.
    Storyfeed::feeds(['kitchen' => CustomerFeed::class, 'staff' => AdminFeed::class]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('order.placed', $customer)->context($customer)->publish();

    // A subject feed has one door in — its constructor — and it now reports
    // the key it was registered under, not the name of its class.
    $item = CustomerFeed::make($customer)->get()->toArray()['items'][0];

    expect(Customer::$lastContext?->feed())->toBe('kitchen')
        ->and($item['object']['url'])->toBe("/kitchen/customers/{$customer->id}")
        ->and(CustomerFeed::name())->toBe('kitchen')
        ->and(CustomerFeed::make($customer)->declaredFeed())->toBe('kitchen');

    // A constructable feed has three doors, and they agree.
    Storyfeed::feed('staff')->get()->toArray();
    $byKey = Customer::$lastContext?->feed();

    AdminFeed::make()->get()->toArray();
    $byConstructor = Customer::$lastContext?->feed();

    Storyfeed::feed(AdminFeed::class)->get()->toArray();
    $byClassString = Customer::$lastContext?->feed();

    expect([$byKey, $byConstructor, $byClassString])->toBe(['staff', 'staff', 'staff'])
        ->and(AdminFeed::name())->toBe('staff');
});

it('names a class registered under two keys by the first, deterministically', function () {
    // Two surfaces sharing one allowlist is allowed. Entered by key each
    // reports its own; entered through the class there is no key to prefer,
    // so the first registration — declaration order, merge order across
    // feeds() calls — is the canonical one. Stated, not left to chance.
    Storyfeed::feeds(['staff' => AdminFeed::class]);
    Storyfeed::feeds(['ops' => AdminFeed::class]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    Storyfeed::feed('ops')->get()->toArray();
    $ops = Customer::$lastContext?->feed();

    Storyfeed::feed(AdminFeed::class)->get()->toArray();
    $byClass = Customer::$lastContext?->feed();

    expect($ops)->toBe('ops')
        ->and($byClass)->toBe('staff')
        ->and(AdminFeed::name())->toBe('staff')
        ->and(Storyfeed::feedNameFor(AdminFeed::class))->toBe('staff');

    // Re-registering without merge forgets the earlier claim.
    Storyfeed::feeds(['ops' => AdminFeed::class], merge: false);

    expect(AdminFeed::name())->toBe('ops');
});

it('does not let a registered key leak into a bare list registration of the same class', function () {
    // Registration derives; entry canonicalizes. A bare AdminFeed::class
    // entry names itself 'admin' even when 'staff' => AdminFeed::class is
    // already registered — otherwise it would silently re-register as
    // 'staff' and replace it.
    Storyfeed::feeds(['staff' => AdminFeed::class]);
    Storyfeed::feeds([AdminFeed::class]);

    expect(Storyfeed::feedNames())->toBe(['staff', 'admin'])
        ->and(AdminFeed::name())->toBe('staff')
        ->and(Storyfeed::feedNameFor(CustomerFeed::class))->toBeNull();
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
