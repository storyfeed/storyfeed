<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The readers over real reads: `get()` yields FeedItems that read exactly
 * the arrays `toArray()` returns, and the JSON is those arrays.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    $this->dana = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

afterEach(fn () => Carbon::setTestNow());

it('iterates a page as FeedItems that read the page\'s own arrays', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object');
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();

    $page = Storyfeed::feed()->get();
    $items = iterator_to_array($page);

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(FeedItem::class)
        ->and($items[0]->toArray())->toBe($page->toArray()[0])
        ->and($page->map->toArray()->all())->toBe($page->toArray())
        ->and($items[0]->headline()->toString())->toBe('Dana confirmed '.$page->toArray()[0]['object']['label'])
        ->and($items[0]->actor()->label())->toBe('Dana')
        ->and($items[0]->object()->type())->toBe('delivery');
});

it('leaves toArray() and the JSON as the nodes themselves', function () {
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();

    $page = Storyfeed::feed()->get();

    expect($page->toArray()[0])->toBeArray()
        ->and($page[0])->toBeInstanceOf(FeedItem::class)
        ->and(json_decode(json_encode($page), true))->toBe($page->toArray())
        ->and(json_decode($page->toJson(), true)[0]['kind'])->toBe('activity');
});

it('reads a real group and its children', function () {
    config(['storyfeed.grouping.children_limit' => 2]);

    foreach (range(1, 4) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => "TN-G{$i}"]))
            ->by($this->dana)->publish();
    }

    $group = Storyfeed::feed()->live()->get()->sole();

    expect($group->isGroup())->toBeTrue()
        ->and($group->count())->toBe(4)
        ->and($group->children())->toHaveCount(2)
        ->and($group->childrenTruncated())->toBeTrue()
        ->and($group->children()->first()->isActivity())->toBeTrue()
        ->and($group->distinct('objects'))->toBe(4)
        ->and($group->objects()->count())->toBe(count($group['sample']['objects']))
        ->and($group->headline()->toString())->not->toBe('');
});

it('reads a real tombstone', function () {
    Storyfeed::activity('confirm', $this->delivery)->by($this->dana)->publish();
    $this->delivery->delete();

    $item = Storyfeed::feed()->get()->sole();

    expect($item->object()->isTombstone())->toBeTrue()
        ->and($item->object()->formerType())->toBe('delivery')
        ->and($item->tombstoned())->toBe(['object'])
        ->and($item->isRedundant())->toBeTrue()
        ->and($item->object()->toString())->toBe('a removed delivery');
});

it('renders in Blade', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object');
    Storyfeed::anonymous()->action('confirm', $this->delivery)->publish();

    $html = Blade::render('@foreach ($page as $item){{ $item->headline() }}@endforeach', ['page' => Storyfeed::feed()->get()]);

    expect($html)->toStartWith('Someone confirmed ');
});
