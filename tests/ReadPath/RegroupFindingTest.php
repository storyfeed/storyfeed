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

it('keeps a live cursor\'s place across a rehash, since tied groups order by member id', function () {
    Storyfeed::axes([Axis::make('repeat')->key('v')->fallback()], merge: false);
    $at = now();
    $first = Storyfeed::activity('middle')->publishedAt($at)->publish();
    $second = Storyfeed::activity('zebra')->publishedAt($at)->publish();
    $page = Storyfeed::feed()->live()->limit(1)->cursorPaginate()->toArray();

    // Newest first at one instant, as log() reads them.
    expect($page['data'][0]['id'])->toBe($second->uid)
        ->and($page['next_cursor'])->not->toBeNull();

    // A rehash renames both groups. Their order rests on their ids, which
    // it cannot change, so the cursor's next page is still the older row.
    Storyfeed::axes([
        Axis::make('repeat')->key(fn ($activity) => $activity->verb === 'middle' ? 'zulu' : 'alpha')->fallback(),
    ], merge: false);
    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();
    $next = Storyfeed::feed()->live()->limit(1)->cursorPaginate(cursor: $page['next_cursor'])->toArray();

    expect(array_column($next['data'], 'id'))->toBe([$first->uid])
        ->and($next['sync_token'])->not->toBe($page['sync_token']);
});
