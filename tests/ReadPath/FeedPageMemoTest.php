<?php

use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;

it('presents each node once across repeated page readers', function () {
    $slice = new GroupSlice(null, null, 1, collect());
    $calls = 0;
    $presenter = Mockery::mock(NodePresenter::class);
    $presenter->shouldReceive('forPage')->andReturnSelf();
    $presenter->shouldReceive('node')->with($slice)->andReturnUsing(function () use (&$calls) {
        $calls++;

        return ['id' => 'activity:1'];
    });
    $page = new FeedPage(collect([$slice]), 'opaque-cursor', $presenter, 'opaque-sync');

    expect($calls)->toBe(0);
    $items = $page['items'];
    expect($page['next_cursor'])->toBe('opaque-cursor')
        ->and(isset($page['items']))->toBeTrue()
        ->and(isset($page['missing']))->toBeFalse()
        ->and($page['missing'])->toBeNull()
        ->and($page->toArray())->toBe([
            'payload_version' => 1,
            'items' => $items,
            'next_cursor' => 'opaque-cursor',
            'sync_token' => 'opaque-sync',
        ])
        ->and($page->items())->toBe($items)
        ->and($page->jsonSerialize()['items'])->toBe($items)
        ->and($page->collect()->first()->toArray())->toBe($items[0])
        ->and($calls)->toBe(1);

    // Memoized values belong to this page, never to its shared presenter.
    $another = new FeedPage(collect([$slice]), null, $presenter);
    expect($another->items())->toBe($items)
        ->and($calls)->toBe(2);
});
