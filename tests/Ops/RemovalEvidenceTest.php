<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Contracts\FeedHealer;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Healing\HealFeed;
use Storyfeed\Healing\HealOutcome;
use Storyfeed\Healing\Removal;
use Storyfeed\Healing\Removals;
use Storyfeed\Healing\StoryRetirement;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Delivery;

/**
 * Removal evidence: a marker per story key that outlives the row it
 * describes, so "no row on this key" stops being ambiguous between "someone
 * deleted it" and "nothing was ever recorded". HealFeed does not consult it
 * yet; these tests prove the distinction against the public query.
 */
function removalStory(int $objectId = 1, ?Carbon $publishedAt = null, bool $replace = false, string $verb = 'asset.published'): Activity
{
    $pending = Storyfeed::activity()->verb($verb)->anonymously()->publishedAt($publishedAt ?? now()->subDays(60));
    $pending->activity->object_type = 'external_asset';
    $pending->activity->object_id = $objectId;

    return $replace ? $pending->replace()->publish() : $pending->publish();
}

function removalHealer(array $retirements): void
{
    Storyfeed::healers([new class($retirements) implements FeedHealer
    {
        public function __construct(private array $items) {}

        public function key(): string
        {
            return 'assets';
        }

        public function candidates(): iterable
        {
            yield from $this->items;
        }
    }]);
}

/** @return list<string> */
function removalStatements(callable $during): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements) {
        if (str_contains($query->sql, 'feed_removals')) {
            $statements[] = $query->sql;
        }
    });

    $during();

    return $statements;
}

it('tells a deliberate removal from a key never recorded, after pruning erased the row', function () {
    config()->set('storyfeed.prune.after_days', 30);
    $removed = removalStory(objectId: 1);
    removalStory(objectId: 2, publishedAt: now());

    $removed->delete();
    (new PruneActivities)();

    $evidence = Removals::removed('asset.published', 'external_asset', 1);

    expect(Activity::withTrashed()->where('object_id', 1)->exists())->toBeFalse()
        ->and($evidence)->toBeInstanceOf(Removal::class)
        ->and($evidence->verb)->toBe('asset.published')
        ->and($evidence->objectType)->toBe('external_asset')
        ->and($evidence->objectId)->toEqual(1)
        ->and($evidence->removedAt->equalTo(now()))->toBeTrue()
        ->and($evidence->publishedAt->equalTo($removed->published_at))->toBeTrue()
        // Same verb, same type, never recorded: the other half of the distinction.
        ->and(Removals::removed('asset.published', 'external_asset', 3))->toBeNull()
        // Live on its key: no evidence, whatever the table says.
        ->and(Removals::removed('asset.published', 'external_asset', 2))->toBeNull();
});

it('writes nothing for supersession, and evidence once the successor is removed', function () {
    config()->set('storyfeed.prune.after_days', 30);
    removalStory(objectId: 1);
    $successor = removalStory(objectId: 1, publishedAt: now(), replace: true);

    expect(Activity::withTrashed()->count())->toBe(2)
        ->and(Activity::count())->toBe(1)
        ->and(Removals::query()->count())->toBe(0);

    // Purging the superseded row is not a removal either.
    (new PruneActivities)();

    expect(Activity::withTrashed()->count())->toBe(1)
        ->and(Removals::query()->count())->toBe(0);

    $successor->delete();

    expect(Removals::removed('asset.published', 'external_asset', 1))->not->toBeNull();
});

it('writes nothing for supersession in force mode', function () {
    config()->set('storyfeed.replace.delete', 'force');
    removalStory(objectId: 1);
    removalStory(objectId: 1, replace: true);

    expect(Activity::withTrashed()->count())->toBe(1)
        ->and(Removals::query()->count())->toBe(0);
});

it('is moot while a live story is back on the key, and refreshed when that one goes too', function () {
    $first = removalStory(objectId: 1);
    $first->delete();
    $firstRemoval = Removals::removed('asset.published', 'external_asset', 1);

    $second = removalStory(objectId: 1);

    expect($firstRemoval)->not->toBeNull()
        ->and(Removals::removed('asset.published', 'external_asset', 1))->toBeNull();

    $this->travel(1)->hours();
    $second->delete();

    expect(Removals::removed('asset.published', 'external_asset', 1)->removedAt->greaterThan($firstRemoval->removedAt))->toBeTrue()
        ->and(Removals::query()->count())->toBe(1);
});

it('records every key a force-deleted Feedable empties, in one upsert per chunk', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $other = Delivery::create(['tracking_number' => 'TN-2']);
    Storyfeed::activity('confirm', $delivery)->publish();
    Storyfeed::activity('confirm', $delivery)->publish();
    Storyfeed::activity('dispatch', $delivery)->publish();
    Storyfeed::activity('confirm', $other)->publish();

    $statements = removalStatements(fn () => $delivery->forceDelete());

    expect(Activity::withTrashed()->count())->toBe(1)
        ->and(Removals::query()->count())->toBe(2)
        ->and(Removals::removed('confirm', $delivery))->not->toBeNull()
        ->and(Removals::removed('dispatch', $delivery))->not->toBeNull()
        ->and(Removals::removed('confirm', $other))->toBeNull()
        ->and($statements)->toHaveCount(1)
        ->and($statements[0])->toStartWith('insert into');
});

it('records a soft-deleted Feedable the same way, then purges its rows without writing again', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('confirm', $delivery)->publish();

    $delivery->delete();

    expect(Activity::count())->toBe(0)
        ->and(Activity::withTrashed()->count())->toBe(1)
        ->and(Removals::removed('confirm', $delivery))->not->toBeNull();

    $removedAt = Removals::removed('confirm', $delivery)->removedAt;
    $this->travel(1)->hours();

    $statements = removalStatements(fn () => $delivery->forceDelete());

    expect(Activity::withTrashed()->count())->toBe(0)
        ->and($statements)->toBe([])
        ->and(Removals::removed('confirm', $delivery)->removedAt->equalTo($removedAt))->toBeTrue();
});

it('is never swept by prune, which touches the evidence table not at all', function () {
    config()->set('storyfeed.prune.after_days', 30);

    foreach (range(1, 20) as $objectId) {
        removalStory(objectId: $objectId)->delete();
    }

    expect(Removals::query()->count())->toBe(20);

    $statements = removalStatements(fn () => (new PruneActivities)());

    expect(Activity::withTrashed()->count())->toBe(0)
        ->and($statements)->toBe([])
        ->and(Removals::query()->count())->toBe(20)
        ->and(Removals::removed('asset.published', 'external_asset', 7))->not->toBeNull();
});

it('advances the retention watermark after each sweep and never lowers it', function () {
    removalStory(objectId: 1, publishedAt: now()->subDays(100));

    (new PruneActivities)();

    expect(Removals::prunedBefore())->toBeNull();

    (new PruneActivities)(30);

    expect(Removals::prunedBefore()->equalTo(now()->subDays(30)))->toBeTrue()
        ->and(Removals::query()->count())->toBe(0);

    (new PruneActivities)(90);

    expect(Removals::prunedBefore()->equalTo(now()->subDays(30)))->toBeTrue();

    (new PruneActivities)(10);

    expect(Removals::prunedBefore()->equalTo(now()->subDays(10)))->toBeTrue();
});

it('has no key for an activity without an object', function () {
    $activity = Storyfeed::activity()->verb('ping')->anonymously()->publish();

    $activity->delete();

    expect(Removals::query()->count())->toBe(0)
        ->and(fn () => Removals::removed('ping', 'external_asset'))
        ->toThrow(InvalidArgumentException::class, 'has no key');
});

it('keys composite members like any story, and the object-less parent not at all', function () {
    $first = Delivery::create(['tracking_number' => 'TN-1']);
    $second = Delivery::create(['tracking_number' => 'TN-2']);
    $parent = Storyfeed::activity()->verb('confirm')->objects([$first, $second])->publish();
    $member = Activity::query()->where('object_id', $second->getKey())->sole();

    $parent->delete();

    expect(Removals::query()->count())->toBe(0);

    $member->delete();

    expect(Removals::removed('confirm', $second))->not->toBeNull()
        ->and(Removals::removed('confirm', $first))->toBeNull();
});

it('leaves no evidence behind a rolled-back delete', function () {
    $activity = removalStory(objectId: 1);

    try {
        DB::transaction(function () use ($activity) {
            $activity->delete();

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect($activity->fresh()->trashed())->toBeFalse()
        ->and(Removals::query()->count())->toBe(0);
});

it('outlives a retirement the healer applied, so the healer could ask — and still does not', function () {
    config()->set('storyfeed.prune.after_days', 30);
    $retired = removalStory(objectId: 1);
    removalHealer([new StoryRetirement('Absent asset', $retired->id, fn (Activity $live): bool => true)]);

    $results = iterator_to_array(app(HealFeed::class)->run(), false);
    (new PruneActivities)();

    expect($results[0]->outcome)->toBe(HealOutcome::Retired)
        ->and(Activity::withTrashed()->count())->toBe(0)
        ->and(Removals::removed('asset.published', 'external_asset', 1))->not->toBeNull()
        ->and(Removals::removed('asset.published', 'external_asset', 2))->toBeNull();

    // The healer's guarantee is unchanged: absence is never an instruction.
    $again = iterator_to_array(app(HealFeed::class)->run(), false);

    expect($again[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and(Activity::withTrashed()->count())->toBe(0);
});
