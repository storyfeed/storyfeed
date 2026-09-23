<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\ForceDeleteFromFeed;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Todo 1348: a composite parent erased by ForceDeleteFromFeed released
 * nothing, so its members stayed claimed by a parent that no longer existed
 * and the story went on rendering from them. The fix releases them first,
 * as prune and an Eloquent force delete do; the repair
 * (`storyfeed:curate --release`) brings earlier erasures into line, and the
 * doctor's `claims` check counts what is left.
 */

beforeEach(function () {
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
});

/** A composite of `$count` uploads; returns [parent, member ids]. */
function uploadComposite(User $actor, int $count = 3): array
{
    $parent = Storyfeed::activity('upload')
        ->actor($actor)
        ->objects(collect(range(1, $count))->map(fn ($i) => Delivery::create(['tracking_number' => "File-{$i}"]))->all())
        ->publish();

    $members = Grouping::query()->where('bucket', 'composite')->where('hash', $parent->uid)
        ->where('activity_id', '!=', $parent->id)->orderBy('activity_id')->pluck('activity_id')->all();

    return [$parent, $members];
}

/** The old defect's shape: the parent and its own rows go, the members' claims stay. */
function eraseParentWithoutRelease(Activity $parent): void
{
    DB::table('feed_groupings')->where('activity_id', $parent->id)->delete();
    DB::table('feed_participants')->where('activity_id', $parent->id)->delete();
    DB::table('feed_activities')->where('id', $parent->id)->delete();
}

/** How many activities the feed shows, counting through groups. */
function shown(): int
{
    return collect(Storyfeed::feed()->limit(50)->get()->toArray()['items'])->sum(fn (array $item) => $item['count'] ?? 1);
}

/** @return list<array<string, mixed>> */
function groupingRows(): array
{
    return Grouping::query()->orderBy('activity_id')->orderBy('bucket')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();
}

describe('ForceDeleteFromFeed', function () {
    it('releases the members of a composite parent it erases', function () {
        [$parent, $members] = uploadComposite($this->sally);

        (new ForceDeleteFromFeed)->activities(fn () => Activity::query()->withTrashed()->whereKey($parent->id));

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect(Activity::query()->find($parent->id))->toBeNull()
            ->and(Grouping::query()->where('bucket', 'composite')->count())->toBe(0)
            ->and($items)->toHaveCount(1)
            ->and($items[0]['axis'])->toBe('repeat')
            ->and($items[0]['count'])->toBe(3);
    });

    it('leaves a lone surviving member solo when a member goes with the parent', function () {
        [$parent, $members] = uploadComposite($this->sally, 2);

        (new ForceDeleteFromFeed)->activities(fn () => Activity::query()->withTrashed()->whereKey([$parent->id, $members[0]]));

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0]['kind'])->toBe('activity')
            ->and($items[0]['id'])->toBe(Activity::query()->findOrFail($members[1])->uid)
            ->and(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);
    });

    it('releases nothing when the parent is only trashed', function () {
        [$parent] = uploadComposite($this->sally);

        $parent->delete();

        expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(4)
            ->and(Storyfeed::doctor(['claims'])->all())->toBeEmpty();
    });
});

describe('storyfeed:curate --release', function () {
    it('releases members an earlier erasure left claimed, and the doctor sees them go', function () {
        [$parent, $members] = uploadComposite($this->sally);
        eraseParentWithoutRelease($parent);

        // Not hidden: the story renders from its members' claims.
        expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('composite')
            ->and(shown())->toBe(3);

        $finding = Storyfeed::doctor(['claims'])->all()[0];

        expect($finding->code)->toBe('claims.parent_gone')
            ->and($finding->severity)->toBe(Severity::Info)
            ->and($finding->subject['claimed'])->toBe(3);

        $token = SyncToken::current();

        $this->artisan('storyfeed:curate --release')
            ->expectsOutputToContain('Released 3 composite members whose parent no longer exists.')
            ->assertSuccessful();

        expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('repeat')
            ->and(shown())->toBe(3)
            ->and(Grouping::query()->where('bucket', 'composite')->count())->toBe(0)
            ->and(Storyfeed::doctor(['claims'])->all())->toBeEmpty()
            ->and(SyncToken::current())->not->toBe($token);
    });

    it('renders a released member solo', function () {
        [$parent, $members] = uploadComposite($this->sally, 2);
        eraseParentWithoutRelease($parent);
        // A member hard-deleted by some other path; its claim went with it.
        DB::table('feed_groupings')->where('activity_id', $members[0])->delete();
        DB::table('feed_activities')->where('id', $members[0])->delete();

        $this->artisan('storyfeed:curate --release')->assertSuccessful();

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0]['kind'])->toBe('activity');
    });

    it('releases a trashed member too, so a restore brings it back released', function () {
        [$parent, $members] = uploadComposite($this->sally, 2);
        Activity::query()->findOrFail($members[0])->delete();
        eraseParentWithoutRelease($parent);

        $this->artisan('storyfeed:curate --release')
            ->expectsOutputToContain('Released 2 composite members')
            ->assertSuccessful();

        Activity::query()->withTrashed()->findOrFail($members[0])->restore();

        expect(shown())->toBe(2);
    });

    it('is a no-op on a healthy feed', function () {
        uploadComposite($this->sally);
        Storyfeed::activity()->actor($this->sally)->verb('ping')->publish();
        [$trashed] = uploadComposite($this->sally, 2);
        $trashed->delete();

        $before = groupingRows();
        $token = SyncToken::current();

        $this->artisan('storyfeed:curate --release')
            ->expectsOutputToContain('Released 0 composite members')
            ->assertSuccessful();

        expect(groupingRows())->toBe($before)
            ->and(SyncToken::current())->toBe($token);
    });

    it('changes nothing the second time', function () {
        [$parent] = uploadComposite($this->sally);
        eraseParentWithoutRelease($parent);

        $this->artisan('storyfeed:curate --release')->assertSuccessful();

        $after = groupingRows();
        $token = SyncToken::current();

        $this->artisan('storyfeed:curate --release')
            ->expectsOutputToContain('Released 0 composite members')
            ->assertSuccessful();

        expect(groupingRows())->toBe($after)
            ->and(SyncToken::current())->toBe($token);
    });
});
