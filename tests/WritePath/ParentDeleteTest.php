<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\Feedables;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Storyfeed\Tests\Fixtures\Models\FeedablePhoto;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Workbench\App\Models\User;

/** A concrete, non-Feedable middle layer, like an app's own Media subclass. */
class ParentDeleteTaggedPhoto extends Photo
{
    protected $table = 'photos';
}

class ParentDeleteFeedableTaggedPhoto extends ParentDeleteTaggedPhoto implements Feedable
{
    use InteractsWithFeed;
}

abstract class ParentDeleteAbstractPhoto extends Photo {}

class ParentDeleteFeedableAbstractPhoto extends ParentDeleteAbstractPhoto implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'photos';
}

/** A subclass over a table of its own: its keys name different rows. */
class ParentDeleteOwnTablePhoto extends Photo implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'dishes';
}

/** A vendor subclass made Feedable by registration rather than the interface. */
class ParentDeleteVendorPhoto extends Photo
{
    protected $table = 'photos';
}

function hearPhotosAs(array $map): void
{
    Relation::morphMap($map);
    app(Feedables::class)->listenThroughParents(Relation::morphMap());
}

beforeEach(function () {
    // The morph map is static: what these tests add must not reach the
    // next test's boot, where the map is read.
    $this->morphMap = Relation::morphMap();
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

afterEach(function () {
    Relation::morphMap($this->morphMap, false);
});

describe('a Feedable subclass deleted through its parent', function () {
    beforeEach(function () {
        hearPhotosAs(['feedable_photo' => FeedablePhoto::class]);
        $this->photo = FeedablePhoto::create(['file_name' => 'soup.jpg']);
        $this->activity = Storyfeed::activity()->actor($this->ines)->verb('upload', $this->photo)->publish();
    });

    it('is tombstoned at once, restorably, when the parent is soft-deleted', function () {
        Photo::findOrFail($this->photo->id)->delete();

        $tombstone = FeedTombstone::for('feedable_photo', $this->photo->id);

        expect($tombstone)->not->toBeNull()
            ->and($tombstone->restorable)->toBeTrue()
            ->and($tombstone->approximate)->toBeFalse()
            ->and($this->activity->fresh()->object_type)->toBe(FeedTombstone::MORPH_ALIAS)
            ->and(Snapshot::query()->where('model_type', 'feedable_photo')->exists())->toBeFalse();
    });

    it('is taken back when the parent is restored', function () {
        $parent = Photo::findOrFail($this->photo->id);
        $parent->delete();
        $parent->restore();

        expect(FeedTombstone::query()->count())->toBe(0)
            ->and($this->activity->fresh()->object_type)->toBe('feedable_photo')
            ->and((string) $this->activity->fresh()->object_id)->toBe((string) $this->photo->id)
            ->and(Snapshot::query()->where('model_type', 'feedable_photo')->value('label'))->toBe('soup.jpg');
    });

    it('leaves a permanent tombstone on a force delete, trashed first or not', function (bool $trashFirst) {
        $parent = Photo::findOrFail($this->photo->id);

        if ($trashFirst) {
            $parent->delete();
        }

        $parent->forceDelete();

        expect(FeedTombstone::query()->count())->toBe(1)
            ->and(FeedTombstone::for('feedable_photo', $this->photo->id)->restorable)->toBeFalse()
            ->and($this->activity->fresh()->object_type)->toBe(FeedTombstone::MORPH_ALIAS);
    })->with(['trashed first' => true, 'straight away' => false]);

    it('does nothing while recording is off, and the trickle still finds it, restorably', function () {
        $parent = Photo::findOrFail($this->photo->id);
        Storyfeed::withoutRecording(fn () => $parent->delete());

        expect(FeedTombstone::query()->count())->toBe(0);

        // The sweep sees the trashed row as absent (the default scope hides
        // it) but checks existence without scopes, so it knows it can return.
        (new TrickleSnapshots)();
        $tombstone = FeedTombstone::for('feedable_photo', $this->photo->id);

        expect($tombstone->restorable)->toBeTrue()
            ->and($this->activity->fresh()->object_type)->toBe(FeedTombstone::MORPH_ALIAS);

        $parent->restore();

        expect(FeedTombstone::query()->count())->toBe(0)
            ->and($this->activity->fresh()->object_type)->toBe('feedable_photo');
    });
});

it('does nothing for a parent row no activity names, in one query', function () {
    hearPhotosAs(['feedable_photo' => FeedablePhoto::class]);
    $featured = FeedablePhoto::create(['file_name' => 'featured.jpg']);
    Storyfeed::activity()->actor($this->ines)->verb('upload', $featured)->publish();
    Photo::create(['file_name' => 'warm-up.pdf'])->delete();
    $other = Photo::create(['file_name' => 'someone-elses-attachment.pdf']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $other->delete();

    // The soft delete's own update, then the one probe. (The table check
    // before it is made once per process, and was made by the warm-up.)
    expect($queries)->toHaveCount(2)
        ->and($queries[1])->toContain('feed_participants')
        ->and(FeedTombstone::query()->count())->toBe(0)
        ->and(Snapshot::query()->where('model_id', (string) $other->id)->exists())->toBeFalse();
});

it('costs one query per row on a many-row purge, and only named rows do more', function () {
    hearPhotosAs(['feedable_photo' => FeedablePhoto::class]);
    $photos = collect(range(1, 50))->map(fn ($i) => Photo::create(['file_name' => "{$i}.jpg"]));
    $named = FeedablePhoto::findOrFail($photos[10]->id);
    Storyfeed::activity()->actor($this->ines)->verb('upload', $named)->publish();

    $probes = 0;
    DB::listen(function ($query) use (&$probes) {
        $probes += str_contains($query->sql, 'distinct') && str_contains($query->sql, 'feed_participants') ? 1 : 0;
    });

    Photo::query()->get()->each->delete();

    expect($probes)->toBe(50)
        ->and(FeedTombstone::query()->pluck('model_id')->all())->toBe([(string) $named->id]);
});

it('listens to nothing when no Feedable subclass needs it', function () {
    app(Feedables::class)->listenThroughParents(Relation::morphMap());

    $listening = fn (string $class) => array_key_exists("eloquent.deleted: {$class}", Event::getRawListeners());

    expect($listening(Photo::class))->toBeFalse();

    hearPhotosAs(['feedable_photo' => FeedablePhoto::class]);

    expect($listening(Photo::class))->toBeTrue();
});

it('walks up to the first Feedable ancestor, skipping abstract ones', function () {
    $feedables = app(Feedables::class);

    expect($feedables->nonFeedableParents(ParentDeleteFeedableTaggedPhoto::class))
        ->toBe([ParentDeleteTaggedPhoto::class, Photo::class])
        ->and($feedables->nonFeedableParents(ParentDeleteFeedableAbstractPhoto::class))->toBe([Photo::class])
        ->and($feedables->nonFeedableParents(Dish::class))->toBe([])
        // Concrete, but a framework base no row is deleted as.
        ->and($feedables->nonFeedableParents(User::class))->toBe([]);

    Storyfeed::feedable(Photo::class);

    expect($feedables->nonFeedableParents(ParentDeleteFeedableTaggedPhoto::class))->toBe([ParentDeleteTaggedPhoto::class]);
});

it('hears a deletion through every non-Feedable ancestor', function () {
    hearPhotosAs(['tagged_photo' => ParentDeleteFeedableTaggedPhoto::class]);
    $a = ParentDeleteFeedableTaggedPhoto::create(['file_name' => 'a.jpg']);
    $b = ParentDeleteFeedableTaggedPhoto::create(['file_name' => 'b.jpg']);
    Storyfeed::activity()->actor($this->ines)->verb('upload', $a)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('upload', $b)->publish();

    ParentDeleteTaggedPhoto::findOrFail($a->id)->delete();
    Photo::findOrFail($b->id)->delete();

    expect(FeedTombstone::query()->where('model_type', 'tagged_photo')->count())->toBe(2);
});

it('ignores a subclass that reads a table of its own', function () {
    hearPhotosAs(['own_table_photo' => ParentDeleteOwnTablePhoto::class]);
    $dish = ParentDeleteOwnTablePhoto::create(['name' => 'Soup']);
    Storyfeed::activity()->actor($this->ines)->verb('upload', $dish)->publish();

    // Same key, different row.
    DB::table('photos')->insert(['id' => $dish->id, 'file_name' => 'x.jpg', 'created_at' => now(), 'updated_at' => now()]);
    Photo::findOrFail($dish->id)->delete();

    expect(FeedTombstone::query()->count())->toBe(0);
});

it('hears a class registered with Storyfeed::feedable() through its parent', function () {
    Relation::morphMap(['vendor_photo' => ParentDeleteVendorPhoto::class]);
    Storyfeed::feedable(ParentDeleteVendorPhoto::class);
    $photo = ParentDeleteVendorPhoto::create(['file_name' => 'v.jpg']);
    Storyfeed::activity()->actor($this->ines)->verb('upload', $photo)->publish();

    Photo::findOrFail($photo->id)->delete();

    expect(app(Feedables::class)->listensThroughParents(ParentDeleteVendorPhoto::class))->toBeTrue()
        ->and(FeedTombstone::for('vendor_photo', $photo->id))->not->toBeNull();
});
