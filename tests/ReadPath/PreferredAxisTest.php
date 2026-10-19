<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Sources\ArraySource;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('selects a declared mixed-verb candidate without changing global curation or losing atomics', function () {
    config(['storyfeed.grouping.curate' => false]);
    Storyfeed::axes([Axis::make('requests')->key(fn ($activity) => $activity->data['link'] ?? null)->bursts()]);
    foreach (['arrival', 'view', 'pdf'] as $verb) {
        Storyfeed::activity($verb)->data(['link' => 'synthetic-link'])->publish();
    }
    Storyfeed::activity('accept')->publish();

    $feed = Storyfeed::feed()->preferAxis('requests');
    $items = $feed->get()->toArray();
    $group = collect($items)->firstWhere('axis', 'requests');
    expect($items)->toHaveCount(2)->and($group['count'])->toBe(3)
        ->and($feed->log()->get())->toHaveCount(4)
        ->and(config('storyfeed.grouping.curate'))->toBeFalse();
});

it('rejects unknown and row-backed preferences', function () {
    expect(fn () => Storyfeed::feed()->preferAxis('missing'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Storyfeed::feed()->preferAxis('composite'))->toThrow(InvalidArgumentException::class);
});

it('keeps scoped counts and member paging exact beyond the child sample', function () {
    config(['storyfeed.grouping.curate' => false, 'storyfeed.grouping.children_limit' => 2]);
    Storyfeed::axes([Axis::make('requests')->key(fn ($activity) => $activity->data['link'] ?? null)->bursts()]);
    $first = Customer::create(['name' => 'First']);
    $other = Customer::create(['name' => 'Other']);
    foreach (range(1, 9) as $i) {
        Storyfeed::activity($i % 2 ? 'view' : 'pdf')->data(['link' => 'same-key'])
            ->context($i < 7 ? $first : $other)->publishedAt(now()->subSeconds(10 - $i))->publish();
    }
    $feed = Storyfeed::feed()->context($first)->preferAxis('requests');
    $node = $feed->get()->toArray()[0];
    expect($node['count'])->toBe(6)->and($node['children'])->toHaveCount(2);
    $ids = [];
    $cursor = null;
    do {
        $page = $feed->members($node['id'], 2, cursor: $cursor);
        $ids = [...$ids, ...collect($page->items())->pluck('id')->all()];
        $cursor = $page->nextCursor();
    } while ($cursor !== null);
    expect($ids)->toHaveCount(6)->and(array_unique($ids))->toHaveCount(6)
        ->and($ids)->toBe((clone $feed)->log()->get()->pluck('id')->all());
});

it('uses the same preference for a source and leaves milestones atomic', function () {
    config(['storyfeed.grouping.curate' => false]);
    Storyfeed::axes([Axis::make('requests')->key(fn ($activity) => $activity->data['link'] ?? null)->bursts()]);
    $activities = collect(['arrival', 'view', 'pdf'])->map(fn ($verb) => Storyfeed::activity($verb)
        ->data(['link' => 'one'])->publishedAt(now()->subMinute())->publish());
    $activities->push(Storyfeed::activity('accept')->publish());
    $source = new ArraySource($activities);
    $items = Storyfeed::feed()->source($source)->preferAxis('requests')->get()->toArray();
    expect($items)->toHaveCount(2)->and(collect($items)->firstWhere('axis', 'requests')['count'])->toBe(3)
        ->and(Storyfeed::feed()->source($source)->preferAxis('requests')->log()->get())->toHaveCount(4);
});

it('keeps authored composites ahead of the preferred candidate', function () {
    config(['storyfeed.grouping.curate' => false]);
    Storyfeed::axes([Axis::make('requests')->key(fn () => 'one')->bursts()]);
    $files = collect(['a', 'b'])->map(fn ($name) => Delivery::create(['tracking_number' => $name]));
    Storyfeed::activity('upload')->objects($files)->publish();
    $items = Storyfeed::feed()->preferAxis('requests')->get()->toArray();
    expect($items)->toHaveCount(1)->and($items[0]['axis'])->toBe('composite')->and($items[0]['count'])->toBe(2);
});
