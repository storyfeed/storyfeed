<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * A FEED THAT DRAWS NO CHILDREN NEED NOT PRESENT THEM (#251, 2026-11-13).
 *
 * `samples(0)` (#248) left every group presenting each loaded member, up to
 * `grouping.children_limit`, for a dashboard card that never drew one.
 * `children(n)` is a presentation cap: the members are still read, so the
 * count, the distinct counts, the headline, pinned roles and samples stay;
 * only the presented list is cut, and the members it drops are never
 * decorated. `children` stays a list, and `children_truncated` says so.
 */

class ChildCapReviewer extends User
{
    protected $table = 'users';

    /** @var array<int|string, int> */
    public static array $resolved = [];

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        $key = (string) $context->key();
        self::$resolved[$key] = (self::$resolved[$key] ?? 0) + 1;

        return FeedMedia::make(url: "/reviewers/{$key}");
    }
}

beforeEach(function () {
    Relation::morphMap(['child-cap-reviewer' => ChildCapReviewer::class]);
    ChildCapReviewer::$resolved = [];
});

/** Eight reviewers on one customer: one group pinning the object. */
function childCapFeed(): void
{
    $customer = Customer::create(['name' => 'Acme']);

    foreach (range(1, 8) as $i) {
        $user = ChildCapReviewer::create(['name' => "Reviewer {$i}", 'email' => "childcap{$i}@example.test"]);
        Storyfeed::activity('review', $customer)->actor($user)->publishedAt(now()->subMinutes(20 - $i))->publish();
    }
}

/** @param  array<string, mixed>  $payload */
function childCapGroup(array $payload): array
{
    $group = collect($payload['data'])->firstWhere('kind', 'group');
    expect($group)->not->toBeNull();

    return $group;
}

it('sends an empty children list and keeps every count, sample and headline', function () {
    childCapFeed();

    $before = childCapGroup(Storyfeed::feed()->cursorPaginate(20)->toArray());
    $after = childCapGroup(Storyfeed::feed()->children(0)->cursorPaginate(20)->toArray());

    expect($before['children'])->toHaveCount(8)
        ->and($before['children_truncated'])->toBeFalse()
        ->and($after['children'])->toBe([])
        ->and($after['children_truncated'])->toBeTrue();

    unset($before['children'], $before['children_truncated'], $after['children'], $after['children_truncated']);
    expect($after)->toBe($before)
        ->and($after['count'])->toBe(8)
        ->and($after['distinct']['actors'])->toBe(8)
        ->and($after['sample']['actors'])->toHaveCount(3)
        ->and($after['object'])->not->toBeNull();
});

it('presents the first members up to the cap', function () {
    childCapFeed();

    $full = childCapGroup(Storyfeed::feed()->cursorPaginate(20)->toArray());
    $three = childCapGroup(Storyfeed::feed()->children(3)->cursorPaginate(20)->toArray());

    expect($three['children'])->toBe(array_slice($full['children'], 0, 3))
        ->and($three['children_truncated'])->toBeTrue()
        ->and($three['headline'])->toBe($full['headline']);

    $roomy = childCapGroup(Storyfeed::feed()->children(8)->cursorPaginate(20)->toArray());
    expect($roomy['children'])->toHaveCount(8)
        ->and($roomy['children_truncated'])->toBeFalse();
});

it('never decorates the members it drops', function () {
    childCapFeed();

    Storyfeed::feed()->cursorPaginate(20)->toArray();
    expect(ChildCapReviewer::$resolved)->toHaveCount(8);

    ChildCapReviewer::$resolved = [];
    Storyfeed::feed()->children(0)->cursorPaginate(20)->toArray();

    // Only the three sample actors are resolved; the five members that
    // appear in no sample and no child are never presented.
    expect(ChildCapReviewer::$resolved)->toHaveCount(3);
});

it('reads the cap from a named feed and from get()', function () {
    childCapFeed();
    Storyfeed::feeds(['card' => fn (FeedBuilder $feed) => $feed->children(0)]);

    $paged = childCapGroup(Storyfeed::feed('card')->cursorPaginate(20)->toArray());
    /** @var FeedItem $item */
    $item = Storyfeed::feed('card')->get()->first(fn (FeedItem $item) => $item->isGroup());

    expect($paged['children'])->toBe([])
        ->and($item->children())->toHaveCount(0)
        ->and($item->childrenTruncated())->toBeTrue()
        ->and($item->count())->toBe(8);
});

it('rejects a negative cap', function () {
    Storyfeed::feed()->children(-1);
})->throws(InvalidArgumentException::class, 'children() takes 0 or more');
