<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\SyncToken;

it('keeps stored hashes after a recipe change until the existing curate rehash command runs', function () {
    Storyfeed::axes([Axis::make('repeat')->key('v:d')->fallback()], merge: false);
    $activities = collect([
        Storyfeed::activity('ping')->publish(),
        Storyfeed::activity('ping')->publish(),
    ]);
    $hashes = fn () => Grouping::query()->whereIn('activity_id', $activities->pluck('id')->all())
        ->where('bucket', 'repeat')->orderBy('activity_id')->pluck('hash')->all();
    $before = $hashes();
    $axis = Axis::make('repeat')->key('v')->fallback();
    Storyfeed::axes([$axis], merge: false);
    $expected = $activities->map(fn ($activity) => $axis->hashFor($activity))->all();

    expect($expected)->not->toBe($before);
    Storyfeed::feed()->get();
    $this->artisan('storyfeed:rebuild')->assertSuccessful();
    $this->artisan('storyfeed:curate')->assertSuccessful();
    expect($hashes())->toBe($before);

    $token = SyncToken::current();
    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    expect($hashes())->toBe($expected)
        ->and(Grouping::query()->whereIn('activity_id', $activities->pluck('id')->all())->where('winner', true)->count())->toBe(2)
        ->and(SyncToken::current())->not->toBe($token);
});

it('requires a fresh page after rehash moves an unread group before a live cursor', function () {
    Storyfeed::axes([Axis::make('repeat')->key('v')->fallback()], merge: false);
    $at = now();
    $first = Storyfeed::activity('middle')->publishedAt($at)->publish();
    $unread = Storyfeed::activity('zebra')->publishedAt($at)->publish();
    $page = Storyfeed::feed()->summary()->limit(1)->get()->toArray();

    expect($page['items'][0]['id'])->toBe($first->uid)
        ->and($page['next_cursor'])->not->toBeNull();

    Storyfeed::axes([
        Axis::make('repeat')->key(fn ($activity) => $activity->verb === 'zebra' ? 'alpha' : 'middle')->fallback(),
    ], merge: false);
    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();
    $next = Storyfeed::feed()->summary()->limit(1)->cursor($page['next_cursor'])->get()->toArray();
    $fresh = Storyfeed::feed()->summary()->get()->toArray();

    expect($next['items'])->toBeEmpty()
        ->and($next['sync_token'])->not->toBe($page['sync_token'])
        ->and(array_column($fresh['items'], 'id'))->toContain($first->uid, $unread->uid);
});
