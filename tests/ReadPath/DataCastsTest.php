<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\ActivityContext;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\PublishQueuedActivity;
use Storyfeed\Stories\Story as StoryClass;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Tests\Fixtures\DataCasts\Cents;
use Storyfeed\Tests\Fixtures\DataCasts\LineItem;
use Storyfeed\Tests\Fixtures\DataCasts\OrderSummary;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/*
 * R&D spike (W59): a verb's casts() are Eloquent's, keyed by data key.
 * ActivityContext::get() reads through them; storage and payload do not
 * change.
 */

/** Publish one `pay` on a delivery and hand back what its headline closure saw. */
function castContext(array $data, array $casts): ActivityContext
{
    $seen = null;
    Story::for(Delivery::class)->verb('pay')->casts($casts)->headline(function (ActivityContext $activity) use (&$seen) {
        $seen = $activity;

        return 'Paid';
    });

    Storyfeed::activity('pay', Delivery::create(['tracking_number' => 'CAST']))->data($data)->publish();
    Storyfeed::feed()->get()->toArray();

    return $seen;
}

it('reads a data key through every kind of Eloquent cast', function () {
    $activity = castContext([
        'order' => new OrderSummary('A-1001', 4200),
        'lines' => [['sku' => 'KALE', 'quantity' => 2], ['sku' => 'OATS', 'quantity' => 1]],
        'status' => ActivityVerb::Confirm,
        'paid_at' => CarbonImmutable::parse('2026-09-26 10:00:00', 'UTC'),
        'count' => '3',
        'tip' => 350,
    ], [
        'order' => OrderSummary::class,
        'lines' => AsCollection::of(LineItem::class),
        'status' => ActivityVerb::class,
        'paid_at' => 'immutable_datetime',
        'count' => 'integer',
        'tip' => Cents::class,
    ]);

    expect($activity->get('order'))->toEqual(new OrderSummary('A-1001', 4200))
        ->and($activity->get('order.total'))->toBe(4200)
        ->and($activity->get('lines'))->toBeInstanceOf(Collection::class)
        ->and($activity->get('lines')->first())->toEqual(new LineItem(['sku' => 'KALE', 'quantity' => 2]))
        ->and($activity->get('status'))->toBe(ActivityVerb::Confirm)
        ->and($activity->get('paid_at'))->toBeInstanceOf(CarbonImmutable::class)
        ->and($activity->get('paid_at')->equalTo('2026-09-26 10:00:00'))->toBeTrue()
        ->and($activity->get('count'))->toBe(3)
        ->and($activity->get('tip'))->toBe('$3.50');
});

it('keeps all() and the typed helpers on the recorded values', function () {
    $activity = castContext(['status' => 'confirm', 'order' => ['number' => 'A-1', 'total' => 1]], [
        'status' => ActivityVerb::class,
        'order' => OrderSummary::class,
    ]);

    expect($activity->all())->toBe(['status' => 'confirm', 'order' => ['number' => 'A-1', 'total' => 1]])
        ->and($activity->enum('status', ActivityVerb::class))->toBe(ActivityVerb::Confirm)
        ->and($activity->string('order.number')->value())->toBe('A-1')
        ->and($activity->get('absent', 'fallback'))->toBe('fallback');
});

it('stores and serves exactly what data() recorded, casts or not', function () {
    castContext(['order' => new OrderSummary('A-1', 1)], ['order' => OrderSummary::class]);

    expect(Activity::sole()->data)->toBe(['order' => ['number' => 'A-1', 'total' => 1]])
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['data'])->toBe(['order' => ['number' => 'A-1', 'total' => 1]]);
});

it('reports a cast that cannot read an old row and answers the recorded value', function () {
    Exceptions::fake();

    $activity = castContext(['status' => 'retired-verb', 'order' => 'not an object'], [
        'status' => ActivityVerb::class,
        'order' => OrderSummary::class,
    ]);

    expect($activity->get('status'))->toBe('retired-verb')
        ->and($activity->get('order'))->toBe('not an object');

    Exceptions::assertReported(ValueError::class);
});

it('carries a DTO through the queue as the array it recorded', function () {
    $seen = null;
    Story::for(Delivery::class)->verb('pay')->casts(['order' => OrderSummary::class])
        ->headline(function (ActivityContext $activity) use (&$seen) {
            $seen = $activity->get('order');

            return 'Paid';
        });

    $pending = Storyfeed::activity('pay', Delivery::create(['tracking_number' => 'Q']))
        ->data(['order' => new OrderSummary('A-7', 700)]);

    $job = unserialize(serialize(new PublishQueuedActivity($pending)));
    $job->handle();
    Storyfeed::feed()->get()->toArray();

    expect(Activity::sole()->data)->toBe(['order' => ['number' => 'A-7', 'total' => 700]])
        ->and($seen)->toEqual(new OrderSummary('A-7', 700));
});

it('compiles casts from a Story class and caches them in the manifest', function () {
    $story = new class extends StoryClass
    {
        public string|array|null $objectType = Delivery::class;

        public string|FeedVerb|BackedEnum|null $verb = 'pay';

        public function toFeedActivity(): ?PendingActivity
        {
            return $this->activity();
        }

        public function headline(): string
        {
            return ':actor paid for :object';
        }

        public function casts(): array
        {
            return ['lines' => AsCollection::of(LineItem::class), 'order' => OrderSummary::class];
        }
    };

    Story::verb('pay', $story::class);
    Artisan::call('storyfeed:cache');

    try {
        $alias = (new Delivery)->getMorphClass();

        expect(app(StoryManifest::class)->read()['casts'])->toBe([
            "{$alias}.pay" => ['lines' => AsCollection::of(LineItem::class), 'order' => OrderSummary::class],
        ])->and(Storyfeed::dataCasts($alias, 'pay'))->toHaveKeys(['lines', 'order']);
    } finally {
        Artisan::call('storyfeed:clear');
    }
});
