<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The readers over real pages: iterating a FeedPage yields FeedItems that
 * read exactly the arrays `items()` returns, and `items()`, `toArray()` and
 * the JSON are what they were.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    $this->dana = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

afterEach(fn () => Carbon::setTestNow());

it('iterates a page as FeedItems that read the page\'s own arrays', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();

    $page = Storyfeed::feed()->get();
    $items = iterator_to_array($page);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(FeedItem::class)
        ->and($items[0]->toArray())->toBe($page->items()[0])
        ->and($page->collect()->map->toArray()->all())->toBe($page->items())
        ->and($items[0]->headline()->toString())->toBe('Dana confirmed '.$page->items()[0]['object']['label'])
        ->and($items[0]->actor()->label())->toBe('Dana')
        ->and($items[0]->object()->type())->toBe('delivery');
});

it('leaves items(), toArray() and the JSON unchanged', function () {
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();

    $page = Storyfeed::feed()->get();

    expect($page->items()[0])->toBeArray()
        ->and($page->toArray()['items'][0])->toBeArray()
        ->and($page['items'][0])->toBeArray()
        // collect() on the page is the envelope still: Arrayable wins over Traversable.
        ->and(collect($page)->keys()->all())->toBe(['payload_version', 'items', 'next_cursor', 'sync_token'])
        ->and(json_decode(json_encode($page), true)['items'][0]['kind'])->toBe('activity');
});

it('reads a real group and its children', function () {
    config(['storyfeed.grouping.children_limit' => 2]);

    foreach (range(1, 4) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => "TN-G{$i}"]))
            ->by($this->dana)->publish();
    }

    $group = Storyfeed::feed()->live()->get()->collect()->sole();

    expect($group->isGroup())->toBeTrue()
        ->and($group->count())->toBe(4)
        ->and($group->children())->toHaveCount(2)
        ->and($group->childrenTruncated())->toBeTrue()
        ->and($group->children()->first()->isActivity())->toBeTrue()
        ->and($group->distinct('objects'))->toBe(4)
        ->and($group->objects()->count())->toBe(count($group['sample']['objects']))
        ->and($group->headline()->toString())->not->toBe('');
});

it('reads a real digest row', function () {
    Storyfeed::activity('check_in')->by($this->dana)->for($this->acme)->publishedAt(now()->setTime(9, 0))->publish();
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publishedAt(now()->setTime(10, 0))->publish();

    $row = Storyfeed::feed()->summary()->get()->collect()->sole();

    expect($row->isDigest())->toBeTrue()
        ->and($row->actor()->label())->toBe('Dana')
        ->and($row->phrases()->map->verb()->all())->toBe(['check_in', 'confirm'])
        ->and($row->headline()->isFallback())->toBeTrue()
        ->and($row->headline()->toString())->toStartWith('Dana check_in (1) and confirm (1)');
});

it('reads a real tombstone', function () {
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();
    $this->delivery->delete();

    $item = Storyfeed::feed()->get()->collect()->sole();

    expect($item->object()->isTombstone())->toBeTrue()
        ->and($item->object()->formerType())->toBe('delivery')
        ->and($item->tombstoned())->toBe(['object'])
        ->and($item->isRedundant())->toBeTrue()
        ->and($item->object()->toString())->toBe('a removed delivery');
});

it('renders in Blade', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::anonymous()->action('confirm', $this->delivery)->publish();

    $html = Blade::render('@foreach ($page as $item){{ $item->headline() }}@endforeach', ['page' => Storyfeed::feed()->get()]);

    expect($html)->toStartWith('Someone confirmed ');
});
