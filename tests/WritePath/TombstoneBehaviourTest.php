<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\PendingTombstone;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * What a Feedable asks of its tombstone, through FeedEntity::tombstone():
 * keepLabel() and forgetActivities().
 */

beforeEach(function () {
    Delivery::$tombstone = null;

    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

afterEach(function () {
    Delivery::$tombstone = null;
});

/**
 * One activity per relationship the delivery can have to a verb.
 *
 * @return array<string, Activity>
 */
function deliveryStories(object $test): array
{
    // "Featured" is about what it features, its target (a repost, in the study).
    Story::verb('feature')->missing('target');

    $trashed = Storyfeed::activity()->actor($test->ines)->verb('dispatch', $test->delivery)->publish();
    $trashed->delete();

    return [
        'object' => Storyfeed::activity()->actor($test->ines)->verb('confirm', $test->delivery)->publish(),
        'trashed' => $trashed,
        'target' => Storyfeed::activity()->actor($test->ines)->verb('note', $test->acme)->to($test->delivery)->publish(),
        'removal' => Storyfeed::activity()->actor($test->ines)->verb('delete', $test->delivery)->publish(),
        'featured' => Storyfeed::activity()->actor($test->ines)->verb('feature', $test->acme)->to($test->delivery)->publish(),
        'actor' => Storyfeed::activity()->actor($test->delivery)->verb('route', $test->acme)->publish(),
    ];
}

/** @param  array<string, Activity>  $stories */
function surviving(array $stories): array
{
    $ids = Activity::query()->withTrashed()->pluck('id')->all();

    return array_keys(array_filter($stories, fn (Activity $activity) => in_array($activity->id, $ids)));
}

it('keeps the label on the tombstone when the model asks', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->keepLabel();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $tombstone = FeedTombstone::sole();

    expect($tombstone->label)->toBe('Delivery #TN-1')
        ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->sole()->label)->toBe('Delivery #TN-1')
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['object']['label'])->toBe('Delivery #TN-1')
        // Still the model's own snapshot is gone.
        ->and(Snapshot::query()->where('model_type', 'delivery')->exists())->toBeFalse();
});

it('keeps no label by default', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->forceDelete();

    expect(FeedTombstone::sole()->label)->toBeNull();
});

it('forgets the activities it made redundant on a hard delete, and only those', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->forgetActivities();
    $stories = deliveryStories($this);
    $token = SyncToken::current();

    $this->delivery->forceDelete();

    expect(surviving($stories))->toBe(['target', 'removal', 'actor'])
        ->and(DB::table(SyncParticipants::table())->whereIn('activity_id', [$stories['object']->id, $stories['featured']->id])->count())->toBe(0)
        ->and(FeedTombstone::sole()->restorable)->toBeFalse()
        ->and(SyncToken::current())->not->toBe($token);
});

it('forgets nothing on a soft delete, so a restore brings everything back', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->forgetActivities();
    $stories = deliveryStories($this);

    $this->delivery->delete();

    expect(surviving($stories))->toBe(array_keys($stories));

    $this->delivery->restore();

    expect(surviving($stories))->toBe(array_keys($stories))
        ->and(Activity::query()->withTrashed()->involving($this->delivery)->count())->toBe(count($stories));
});

it('forgets them when a soft-deleted model is later force-deleted', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->keepLabel()->forgetActivities();
    $stories = deliveryStories($this);

    $this->delivery->delete();
    Delivery::withTrashed()->find($this->delivery->id)->forceDelete();

    expect(surviving($stories))->toBe(['target', 'removal', 'actor'])
        ->and(FeedTombstone::sole()->label)->toBe('Delivery #TN-1');
});

it('forgets nothing on a hard delete unless the model asks', function () {
    $stories = deliveryStories($this);

    $this->delivery->forceDelete();

    expect(surviving($stories))->toBe(array_keys($stories));
});

it('follows a verb\'s declared roles when forgetting', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->forgetActivities();
    Story::verb('confirm')->missing();
    Story::verb('note')->missing('target');
    $stories = deliveryStories($this);

    $this->delivery->forceDelete();

    expect(surviving($stories))->toBe(['object', 'removal', 'actor']);
});

it('supports when() on the configurator', function () {
    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->when(false, fn ($t) => $t->keepLabel());
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->forceDelete();

    expect(FeedTombstone::sole()->label)->toBeNull();
});
