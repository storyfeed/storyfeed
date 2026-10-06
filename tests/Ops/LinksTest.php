<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

class LinksCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return $context->feed() === 'linked' || $context->data('name') === 'Linked'
            ? FeedMedia::make('/customers/'.$context->key())
            : null;
    }
}

class LinksScopedFeed extends Feed
{
    public function define(FeedBuilder $feed): void
    {
        $feed->log()->only(['onboard']);
    }

    protected function scope(FeedBuilder $feed): void
    {
        $feed->context(Customer::query()->where('name', 'Inside')->sole());
    }
}

beforeEach(function () {
    Relation::morphMap(['links_customer' => LinksCustomer::class]);
});

function linksCustomer(string $name = 'Unlinked'): LinksCustomer
{
    return LinksCustomer::create(['name' => $name]);
}

function linksLog(): void
{
    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->log()], merge: false);
}

it('reports only observed null links at Info without changing stored rows', function () {
    linksLog();
    Storyfeed::activity('onboard', linksCustomer())->publish();
    $activities = Activity::query()->get()->toArray();
    $snapshots = Snapshot::query()->get()->toArray();

    $report = Storyfeed::doctor(['links']);
    $finding = $report->withCode('links.missing')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->fix)->toBeNull()
        ->and($finding->subject)->toBe([
            'feed' => 'audit', 'role' => 'object', 'type' => 'links_customer',
            'sampled' => 1, 'items' => 1, 'sample_limit' => 30,
        ])
        ->and($finding->message)->toContain('Unlinked entities are legitimate', 'not group totals', 'another named feed')
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->count())->toBe(0)
        ->and(Activity::query()->get()->toArray())->toBe($activities)
        ->and(Snapshot::query()->get()->toArray())->toBe($snapshots);
});

it('keeps named feed URL evidence separate', function () {
    Storyfeed::feeds([
        'audit' => fn (FeedBuilder $feed) => $feed->log(),
        'linked' => fn (FeedBuilder $feed) => $feed->log(),
    ], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->publish();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject['feed'])->toBe('audit');
});

it('suppresses a tuple if any inspected occurrence links', function () {
    linksLog();
    Storyfeed::activity('onboard', linksCustomer())->publish();
    Storyfeed::activity('onboard', linksCustomer('Linked'))->publish();

    expect(Storyfeed::doctor(['links'])->all())->toBeEmpty();
});

it('keeps role evidence separate for the same type', function () {
    linksLog();
    Storyfeed::activity('onboard', linksCustomer())->actor(linksCustomer('Linked'))->publish();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject['role'])->toBe('object');
});

it('inspects every payload role', function (string $role) {
    linksLog();
    Storyfeed::activity('onboard')->{$role}(linksCustomer())->publish();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject['role'])->toBe($role);
})->with(ActivityRoles::PAYLOAD);

it('uses the actual query scope and verb filter', function () {
    $inside = Customer::create(['name' => 'Inside']);
    $outside = Customer::create(['name' => 'Outside']);
    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->log()->context($inside)->only(['onboard'])], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->context($inside)->publish();
    Storyfeed::activity('onboard', linksCustomer('Linked'))->context($outside)->publish();
    Storyfeed::activity('ignored', linksCustomer('Linked'))->context($inside)->publish();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'role' => 'object', 'sampled' => 1, 'items' => 1,
    ]);
});

it('does not substitute an unscoped subject feed and still inspects other feeds', function () {
    Storyfeed::feeds([
        'subject' => CustomerFeed::class,
        'audit' => fn (FeedBuilder $feed) => $feed->log(),
    ], merge: false);
    Storyfeed::activity('order_placed', linksCustomer())->publish();

    $report = Storyfeed::doctor(['links']);
    expect($report->withCode('links.uninspectable')->sole()->subject['feed'])->toBe('subject')
        ->and($report->withCode('links.missing')->sole()->subject['feed'])->toBe('audit');
});

it('preserves a constructable class feeds scope hook', function () {
    $inside = Customer::create(['name' => 'Inside']);
    $outside = Customer::create(['name' => 'Outside']);
    Storyfeed::feeds(['audit' => LinksScopedFeed::class], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->context($inside)->publish();
    Storyfeed::activity('onboard', linksCustomer('Linked'))->context($outside)->publish();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'feed' => 'audit', 'role' => 'object', 'items' => 1, 'sampled' => 1,
    ]);
});

it('continues after a feed query throws and notices total lack of coverage', function () {
    Storyfeed::feeds(['broken' => fn (FeedBuilder $feed) => $feed->query(function () {
        throw new RuntimeException('query failed');
    })], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->publish();

    $report = Storyfeed::doctor(['links']);
    expect($report->withCode('links.missing'))->toBeEmpty()
        ->and($report->withCode('links.uninspectable'))->toHaveCount(2)
        ->and($report->withCode('links.uninspectable')->last()->subject['inspectable_feeds'])->toBe(0);

    linksLog();
    expect(Storyfeed::doctor(['links'])->withCode('links.missing'))->toHaveCount(1);
});

it('notices recorded traffic with no named feeds without inventing URL evidence', function () {
    Storyfeed::feeds([], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->publish();
    $report = Storyfeed::doctor(['links']);

    expect($report->withCode('links.missing'))->toBeEmpty()
        ->and($report->withCode('links.uninspectable')->sole()->message)->toContain('Links were not audited')
        ->and($report->isHealthy())->toBeTrue();
});

it('is silent without traffic or for an empty scoped page', function () {
    Storyfeed::feeds([], merge: false);
    expect(Storyfeed::doctor(['links'])->all())->toBeEmpty();

    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->log()->only(['absent'])], merge: false);
    Storyfeed::activity('onboard', linksCustomer())->publish();
    expect(Storyfeed::doctor(['links'])->all())->toBeEmpty();
});

it('caps the actual page at thirty top-level items without extrapolating', function () {
    linksLog();
    Storyfeed::activity('onboard', linksCustomer('Linked'))->publishedAt(now()->subHours(2))->publish();
    foreach (range(1, 31) as $i) {
        Storyfeed::activity('onboard', linksCustomer())->publishedAt(now()->subSeconds($i))->publish();
    }

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'items' => 30, 'sampled' => 30, 'sample_limit' => 30,
    ]);
});

it('deduplicates mirrors within a group and never counts unseen members', function () {
    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->live()], merge: false);
    config()->set('storyfeed.grouping.children_limit', 2);
    $customer = linksCustomer();
    $actor = User::create(['name' => 'Reader', 'email' => 'reader@example.test']);
    foreach (range(1, 6) as $i) {
        Storyfeed::activity('onboard', $customer)->actor($actor)->publish();
    }
    $item = Storyfeed::feed('audit')->get()->collect()->sole();
    expect($item->isGroup())->toBeTrue()->and($item->count())->toBe(6)
        ->and($item->children())->toHaveCount(2)->and($item->childrenTruncated())->toBeTrue();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'items' => 1, 'sampled' => 1, 'role' => 'object',
    ]);
});

it('counts distinct returned group entities rather than the group total', function () {
    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->live()], merge: false);
    config()->set('storyfeed.grouping.children_limit', 2);
    $actor = User::create(['name' => 'Reader', 'email' => 'reader@example.test']);
    foreach (range(1, 6) as $i) {
        Storyfeed::activity('onboard', linksCustomer())->actor($actor)->publishedAt(now()->subSeconds($i))->publish();
    }
    $item = Storyfeed::feed('audit')->get()->collect()->sole();
    expect($item->isGroup())->toBeTrue()->and($item->count())->toBe(6)
        ->and($item->children())->toHaveCount(2)->and($item->childrenTruncated())->toBeTrue();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'items' => 1, 'sampled' => 2, 'role' => 'object',
    ]);
});

it('reads digest phrases in the declared summary mode', function () {
    Storyfeed::feeds(['audit' => fn (FeedBuilder $feed) => $feed->summary()], merge: false);
    $actor = User::create(['name' => 'Reader', 'email' => 'reader@example.test']);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity('onboard', linksCustomer())->actor($actor)->publish();
    }
    $item = Storyfeed::feed('audit')->get()->collect()->sole();
    expect($item->isDigest())->toBeTrue()->and($item->phrases())->not->toBeEmpty();

    expect(Storyfeed::doctor(['links'])->withCode('links.missing')->sole()->subject)->toMatchArray([
        'items' => 1, 'sampled' => 3, 'role' => 'object',
    ]);
});

it('skips tombstones and nonfeedable types', function () {
    linksLog();
    $delivery = Delivery::create(['tracking_number' => 'Gone']);
    Storyfeed::activity('confirm', $delivery)->publish();
    $delivery->delete();
    Relation::morphMap(['plain' => Activity::class]);
    Activity::query()->create(['verb' => 'onboard', 'object_type' => 'plain', 'object_id' => 999999, 'published_at' => now()]);

    expect(Storyfeed::doctor(['links'])->all())->toBeEmpty();
});

it('lists the check and keeps null links out of CLI gates and stubs', function () {
    linksLog();
    Storyfeed::activity('onboard', linksCustomer())->publish();
    $this->artisan('storyfeed:doctor --list')->expectsOutput('links')->assertExitCode(0);
    foreach (['warning', 'error'] as $floor) {
        $this->artisan('storyfeed:doctor --only=links --fail-on='.$floor)
            ->expectsOutputToContain('Unlinked entities are legitimate')->assertExitCode(0);
    }
    $this->artisan('storyfeed:doctor --only=links --stubs')
        ->expectsOutput('// Nothing to author — no finding names a registry edit.')->assertExitCode(0);
    Artisan::call('storyfeed:doctor', ['--only' => ['links'], '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['healthy'])->toBeTrue()->and($json['count'])->toBe(0)
        ->and($json['findings'][0]['code'])->toBe('links.missing')
        ->and($json['findings'][0]['severity'])->toBe('info');
});
