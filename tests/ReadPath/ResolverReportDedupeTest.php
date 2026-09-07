<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Serialization\CollectionSerializer;
use Storyfeed\Support\LinkResolver;
use Workbench\App\Models\Customer;

/*
 * ONE BROKEN RESOLVER, ONE REPORT (issue #9, 2026-09-06).
 *
 * A resolver that throws almost never throws for one row: it reached for a
 * panel, or the authenticated user, and a queued digest has neither. Before
 * this, LinkResolver::resolve() was static and reported per ENTITY, so a
 * worker rendering a hundred rows about one broken class wrote a hundred
 * identical reports — and if the listener then failed, a hundred copies of
 * the job's exception landed in failed_jobs with them.
 *
 * The fix is a scope, not a memo: `once` is the goal and `never` is a
 * different bug, so the thing that remembers has to die with the page. That
 * is what the second test below is for — it is the assertion a static memo
 * cannot pass, and it is written whether or not anyone reached for one.
 */

/*
 * TWO CLASSES, DECLARED — NOT TWO ANONYMOUS ONES. `new class extends …` at
 * one code point yields the SAME class on every evaluation, so a helper
 * minting "another broken model" would have handed back the first one and
 * the per-class assertion below would have passed for the wrong reason.
 */
class BrokenCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        throw new RuntimeException('boom');
    }
}

class AlsoBrokenCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        throw new LogicException('crash');
    }
}

beforeEach(function () {
    Relation::morphMap(['boom' => BrokenCustomer::class, 'crash' => AlsoBrokenCustomer::class]);
});

/** N published activities whose object is an entity of $class. */
function rows(string $class, int $count): void
{
    foreach (range(1, $count) as $i) {
        Storyfeed::activity('onboard', $class::create(['name' => "Row {$i}"]))->publish();
    }
}

it('reports a resolver that throws for a whole class once per page, not once per entity', function () {
    rows(BrokenCustomer::class, 3);

    Exceptions::fake();

    $items = Storyfeed::feed()->log()->get()->toArray()['items'];

    expect($items)->toHaveCount(3);
    Exceptions::assertReported(RuntimeException::class);
    Exceptions::assertReportedCount(1);
});

it('does not let one page\'s memo silence the next page', function () {
    // THE ASSERTION THAT CATCHES A STATIC MEMO. Deduping in a static array
    // would make this report once in total: the second digest about the
    // same broken class would say nothing at all, and a resolver that broke
    // and was never mentioned again is worse than one mentioned too often.
    rows(BrokenCustomer::class, 3);

    Exceptions::fake();

    Storyfeed::feed()->log()->get()->toArray();
    Storyfeed::feed()->log()->get()->toArray();

    Exceptions::assertReportedCount(2);
});

it('reports a second broken class on the same page — dedupe is per class, not per page', function () {
    rows(BrokenCustomer::class, 3);
    rows(AlsoBrokenCustomer::class, 2);

    Exceptions::fake();

    expect(Storyfeed::feed()->log()->get()->toArray()['items'])->toHaveCount(5);

    Exceptions::assertReported(RuntimeException::class);
    Exceptions::assertReported(LogicException::class);
    Exceptions::assertReportedCount(2);
});

it('still renders every row, still degrades the link to null', function () {
    // The existing contract, and it does not move: one broken resolver never
    // breaks a feed, and the entity arrives labelled and unlinked.
    rows(BrokenCustomer::class, 3);

    Exceptions::fake();

    $items = Storyfeed::feed()->log()->get()->toArray()['items'];

    expect($items)->toHaveCount(3)
        ->and(array_column(array_column($items, 'object'), 'url'))->toBe([null, null, null])
        ->and(array_column(array_column($items, 'object'), 'label'))->toBe(['Row 3', 'Row 2', 'Row 1'])
        ->and(array_column(array_column($items, 'object'), 'media'))->toBe([null, null, null]);
});

it('gives a fresh resolver a fresh memo, and reports again within none', function () {
    // The scope is the object. Two of them are two scopes — which is what
    // makes "per page" expressible at all — and one of them reports once
    // however many entities of the class it is asked about.
    Exceptions::fake();

    $links = new LinkResolver;

    expect($links->resolve(new FeedContext(type: 'boom', id: 1)))->toBeNull()
        ->and($links->resolve(new FeedContext(type: 'boom', id: 2)))->toBeNull();

    Exceptions::assertReportedCount(1);

    (new LinkResolver)->resolve(new FeedContext(type: 'boom', id: 3));

    Exceptions::assertReportedCount(2);
});

it('does not share a memo between a presenter run and a serializer run', function () {
    rows(BrokenCustomer::class, 3);

    Exceptions::fake();

    Storyfeed::feed()->log()->get()->toArray();

    app(ActivitySerializer::class)->activity(Activity::query()->first());

    // The feed page reported once; the AS2 document is a different surface
    // and reports for itself. A memo either surface could see would make
    // this 1 — and would mean whichever ran first silenced the other.
    Exceptions::assertReportedCount(2);
});

it('reports once for a whole AS2 collection page, not once per document', function () {
    rows(BrokenCustomer::class, 4);

    Exceptions::fake();

    $page = Activity::query()
        ->published()
        ->orderBy('id', 'desc')
        ->cursorPaginate(perPage: 4, cursor: null);

    $document = app(CollectionSerializer::class)->collection($page, 'https://example.test/feed');

    // A null url is an absent key in AS2 (array_filter at the boundary),
    // so the degradation is visible as the object carrying only its name.
    expect($document['orderedItems'])->toHaveCount(4)
        ->and($document['orderedItems'][0]['object'])->not->toHaveKey('url');

    Exceptions::assertReportedCount(1);
});

it('reports once for one document whose roles are all the same broken class', function () {
    $activity = Storyfeed::activity('onboard', BrokenCustomer::create(['name' => 'Object']))
        ->actor(BrokenCustomer::create(['name' => 'Actor']))
        ->for(BrokenCustomer::create(['name' => 'Target']))
        ->publish();

    Exceptions::fake();

    app(ActivitySerializer::class)->activity(
        $activity->fresh(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']),
    );

    Exceptions::assertReportedCount(1);
});
