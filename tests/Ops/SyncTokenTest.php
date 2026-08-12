<?php

use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('is null until the first settled-history rewrite, then surfaces in every mode', function () {
    Storyfeed::activity()->verb('ping')->publish();

    expect(Storyfeed::feed()->get()->toArray()['sync_token'])->toBeNull();

    $token = SyncToken::bump();

    foreach ([Storyfeed::feed(), Storyfeed::feed()->grouped(), Storyfeed::feed()->flat()] as $feed) {
        expect($feed->get()->toArray()['sync_token'])->toBe($token);
    }

    expect(SyncToken::bump())->not->toBe($token);
});

it('bumps on the bundle backfill, and only when something was minted', function () {
    Storyfeed::collectables(['delivery']);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    // Nothing to mint: swept, no bump.
    $this->artisan('storyfeed:bundle')->assertSuccessful();

    expect(SyncToken::current())->toBeNull();

    config()->set('storyfeed.grouping.composite.auto', false);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $this->travel(11)->minutes();
    (new CloseBatches)();

    $this->artisan('storyfeed:bundle')->assertSuccessful();

    expect(SyncToken::current())->not->toBeNull();
});

it('does not bump on live automatic minting — head-page rules cover that', function () {
    Storyfeed::collectables(['delivery']);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $this->travel(11)->minutes();
    (new CloseBatches)(); // auto-mints a composite

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('composite')
        ->and(SyncToken::current())->toBeNull();
});

it('bumps on curate --rehash but not plain curate', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    $this->artisan('storyfeed:curate')->assertSuccessful();

    expect(SyncToken::current())->toBeNull();

    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    expect(SyncToken::current())->not->toBeNull();
});

it('degrades to null when the meta table is missing — the feed never breaks', function () {
    Storyfeed::activity()->verb('ping')->publish();

    Schema::drop(config('storyfeed.tables.meta'));

    $payload = Storyfeed::feed()->get()->toArray();

    expect($payload['items'])->toHaveCount(1)
        ->and($payload['sync_token'])->toBeNull();
});
