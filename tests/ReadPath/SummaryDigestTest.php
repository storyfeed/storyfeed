<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The digest (v0.8): `->summary()` is one row per person per calendar day,
 * across verbs. People whose whole day was ONE activity, the same verb at the
 * same target, share a crowd row. At most three phrases, then "and N more",
 * where N counts the remaining activities. Actorless activities stay solo.
 *
 * The fixture is the docs Introduction's glance, so these assertions are the
 * acceptance picture's row shapes.
 */
function person(string $name): User
{
    return User::firstOrCreate(['email' => str($name)->slug().'@example.com'], ['name' => $name]);
}

function did(?User $actor, string $verb, ?Model $object = null, ?Model $target = null, ?Carbon $at = null): Activity
{
    $pending = $actor === null
        ? Storyfeed::anonymous()->verb($verb, $object)
        : Storyfeed::activity($verb, $object)->actor($actor);

    if ($target !== null) {
        $pending->for($target);
    }

    return $pending->publishedAt($at ?? now())->publish();
}

function thing(string $name): Delivery
{
    return Delivery::create(['tracking_number' => $name]);
}

/** @return list<array<string, mixed>> */
function digest(Period|string $period = Period::Day, int $limit = 30): array
{
    return Storyfeed::feed()->summary($period)->limit($limit)->get()->toArray()['items'];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-25 18:00:00'));
    $this->fair = Customer::create(['name' => 'Fun Fair']);
});

it('reads one row per person per day, across verbs, with phrases in the order the day happened', function () {
    $jasper = person('Jasper Tey');

    did($jasper, 'check_in', null, $this->fair, now()->setTime(10, 0));
    did($jasper, 'ride', thing('Ferris wheel'), $this->fair, now()->setTime(11, 0));
    did($jasper, 'balloon', thing('Red balloon'), $this->fair, now()->setTime(10, 30));
    did($jasper, 'ride', thing('Carousel'), $this->fair, now()->setTime(12, 0));
    did($jasper, 'ride', thing('Zipper'), $this->fair, now()->setTime(13, 0));

    $items = digest();

    expect($items)->toHaveCount(1);

    $row = $items[0];

    expect($row['kind'])->toBe('group')
        ->and($row['axis'])->toBe('summary')
        ->and($row['period'])->toBe('day')
        ->and($row['count'])->toBe(5)
        // Spans verbs: names none, and wears no glyph of its own.
        ->and($row['verb'])->toBeNull()
        ->and($row['glyph'])->toBeNull()
        ->and($row['actor']['label'])->toBe('Jasper Tey')
        ->and($row['headline_template'])->toBeNull()
        // "checked in at the Fun Fair, got a balloon and went on 3 rides":
        // first occurrence, not most members first.
        ->and(array_column($row['phrases'], 'verb'))->toBe(['check_in', 'balloon', 'ride'])
        ->and(array_column($row['phrases'], 'count'))->toBe([1, 1, 3])
        ->and($row['phrases'][2]['distinct']['objects'])->toBe(3)
        ->and($row['phrases'][2]['sample']['objects'])->toHaveCount(3)
        ->and($row['phrases_truncated'])->toBeFalse()
        ->and($row['children'])->toHaveCount(5);
});

it('caps a row at three phrases, and N more counts the activities left over', function () {
    $alexei = person('Alexei');

    did($alexei, 'play', thing('Ring toss'), null, now()->setTime(9, 0));
    did($alexei, 'win', thing('Stuffed Woody Woodpecker'), null, now()->setTime(9, 10));
    did($alexei, 'eat', thing('Corn dog'), null, now()->setTime(9, 20));
    did($alexei, 'ride', thing('Bumper cars'), null, now()->setTime(9, 30));
    did($alexei, 'ride', thing('Tilt-a-Whirl'), null, now()->setTime(9, 40));
    did($alexei, 'play', thing('Whack-a-mole'), null, now()->setTime(9, 50));

    $row = digest()[0];
    $shown = array_sum(array_column($row['phrases'], 'count'));

    expect(array_column($row['phrases'], 'verb'))->toBe(['play', 'win', 'eat'])
        ->and($row['phrases_truncated'])->toBeTrue()
        // "and 2 more": the row's count less what the phrases say.
        ->and($row['count'] - $shown)->toBe(2);
});

it('merges people whose whole day was one identical activity into one crowd row', function () {
    did(person('Murray Bauman'), 'check_in', null, $this->fair, now()->setTime(14, 0));
    did(person('Lucas Sinclair'), 'drink', thing('New Coke'), null, now()->setTime(15, 0));
    did(person('Karen Wheeler'), 'check_in', null, $this->fair, now()->setTime(16, 0));

    $items = digest();

    expect($items)->toHaveCount(2);

    [$crowd, $lucas] = $items;

    // The crowd sits where its newest person would have.
    expect($crowd['kind'])->toBe('group')
        ->and($crowd['axis'])->toBe('summary')
        ->and($crowd['count'])->toBe(2)
        ->and($crowd['verb'])->toBe('check_in')
        ->and($crowd['actor'])->toBeNull()
        ->and(array_column($crowd['sample']['actors'], 'label'))->toBe(['Karen Wheeler', 'Murray Bauman'])
        ->and($crowd['distinct']['actors'])->toBe(2)
        ->and($crowd['distinct']['targets'])->toBe(1)
        ->and($crowd['phrases'])->toHaveCount(1)
        ->and($crowd['phrases'][0]['count'])->toBe(2)
        // One activity alone is a plain activity node, not a row of one.
        ->and($lucas['kind'])->toBe('activity')
        ->and($lucas['actor']['label'])->toBe('Lucas Sinclair');
});

it('keeps a person who did the same thing twice on a row of their own', function () {
    $dustin = person('Dustin Henderson');

    did(person('Murray Bauman'), 'check_in', null, $this->fair, now()->setTime(14, 0));
    did($dustin, 'check_in', null, $this->fair, now()->setTime(15, 0));
    did($dustin, 'check_in', null, $this->fair, now()->setTime(16, 0));

    $items = digest();

    // "Dustin checked in at the Fun Fair 2 times" would be untrue of a row
    // that counted people, so no crowd forms.
    expect($items)->toHaveCount(2)
        ->and($items[0]['actor']['label'])->toBe('Dustin Henderson')
        ->and($items[0]['count'])->toBe(2)
        ->and($items[1]['kind'])->toBe('activity');
});

it('never crowds people at different targets, or on different days', function () {
    $arcade = Customer::create(['name' => 'Palace Arcade']);

    did(person('Murray Bauman'), 'check_in', null, $this->fair, now()->setTime(14, 0));
    did(person('Karen Wheeler'), 'check_in', null, $arcade, now()->setTime(15, 0));
    did(person('Robin Buckley'), 'check_in', null, $this->fair, now()->subDay()->setTime(15, 0));

    expect(collect(digest())->pluck('kind')->all())->toBe(['activity', 'activity', 'activity']);
});

it('leaves an actorless activity solo, and hides nothing', function () {
    $max = person('Max Mayfield');

    did(null, 'report', thing('Rats'), null, now()->setTime(8, 0));
    did($max, 'score', thing('Dig Dug'), null, now()->setTime(9, 0));
    did($max, 'score', thing('Galaga'), null, now()->setTime(9, 30));

    $items = digest();

    expect($items)->toHaveCount(2)
        ->and($items[0]['actor']['label'])->toBe('Max Mayfield')
        ->and($items[0]['count'])->toBe(2)
        ->and($items[1]['kind'])->toBe('activity')
        ->and($items[1]['actor'])->toBeNull();
});

it('orders rows newest first and cuts them per calendar day', function () {
    $robin = person('Robin Buckley');
    $dustin = person('Dustin Henderson');

    did($robin, 'complete', thing('Find where the elevator goes'), null, now()->subDay()->setTime(20, 0));
    did($dustin, 'radio', thing('Suzie'), null, now()->subDays(2)->setTime(21, 0));
    did($dustin, 'radio', thing('Suzie'), null, now()->subDays(2)->setTime(22, 0));
    did($robin, 'complete', thing('Decode the message'), null, now()->setTime(9, 0));

    $items = digest();

    expect($items)->toHaveCount(3)
        ->and(array_column($items, 'published_at'))->toBe([
            now()->setTime(9, 0)->toISOString(),
            now()->subDay()->setTime(20, 0)->toISOString(),
            now()->subDays(2)->setTime(22, 0)->toISOString(),
        ]);
});

it('takes a period as the enum or its value, and rejects an unknown one', function () {
    $robin = person('Robin Buckley');

    // Thursday and Friday of ISO week 39.
    did($robin, 'complete', thing('One'), null, now()->subDay()->setTime(20, 0));
    did($robin, 'complete', thing('Two'), null, now()->setTime(9, 0));

    expect(digest())->toHaveCount(2)
        ->and(digest(Period::Week))->toHaveCount(1)
        ->and(digest('week')[0]['period'])->toBe('week')
        ->and(digest('week')[0]['count'])->toBe(2)
        // Periods never share a row id.
        ->and(digest('week')[0]['id'])->not->toBe(digest('month')[0]['id']);

    expect(fn () => Storyfeed::feed()->summary('fortnight'))
        ->toThrow(InvalidArgumentException::class, 'Unknown summary period [fortnight]. Valid periods: hour, day, week, month.');
});

it('reads each phrase from summary grammar, and never from a wildcard', function () {
    Story::verb('ride')->grouped(fn (GroupBuilder $group) => $group->summary('went on :count rides'));
    Story::verb('balloon')->grouped(Group::summary()->headline('got a balloon'));
    // A whole sentence with a subject: it must not become a phrase.
    Storyfeed::aggregateGrammar(['*.check_in' => ':actors checked in at :target']);

    $jasper = person('Jasper Tey');

    did($jasper, 'check_in', null, $this->fair, now()->setTime(10, 0));
    did($jasper, 'balloon', thing('Red balloon'), $this->fair, now()->setTime(10, 30));
    did($jasper, 'ride', thing('Ferris wheel'), $this->fair, now()->setTime(11, 0));
    did($jasper, 'ride', thing('Carousel'), $this->fair, now()->setTime(12, 0));

    $row = digest()[0];

    expect(array_column($row['phrases'], 'headline_template'))->toBe([null, 'got a balloon', 'went on :count rides'])
        ->and($row['headline_template'])->toBeNull();

    // `summary.*` is the row's own sentence, when an app wants one.
    Storyfeed::aggregateGrammar(['summary.*' => ':actor had a busy day']);

    expect(digest()[0]['headline_template'])->toBe(':actor had a busy day');
});

it('places a composite parent under its person, with its members told by it', function () {
    Storyfeed::aggregateGrammar(['composite.upload' => ':actor uploaded :count files to :target']);

    $tomas = person('Tomás');

    Storyfeed::activity('upload')->actor($tomas)
        ->objects([thing('a.pdf'), thing('b.pdf'), thing('c.pdf')])
        ->for($this->fair)
        ->publishedAt(now()->setTime(10, 0))
        ->publish();
    did($tomas, 'comment', thing('Nice'), $this->fair, now()->setTime(11, 0));

    $row = digest()[0];

    // The parent and the comment: two activities, not five.
    expect(digest())->toHaveCount(1)
        ->and($row['count'])->toBe(2)
        ->and(array_column($row['phrases'], 'verb'))->toBe(['upload', 'comment']);
});

it('pages through the digest without losing or repeating an activity', function () {
    foreach (['Will Byers', 'Mike Wheeler', 'Nancy Wheeler', 'Steve Harrington', 'Eleven'] as $i => $name) {
        foreach (range(0, $i) as $n) {
            did(person($name), $n % 2 === 0 ? 'ride' : 'play', thing("{$name}-{$n}"), null, now()->subDays($n % 3)->setTime(10 + $i, $n));
        }
    }

    $seen = 0;
    $cursor = null;

    do {
        $page = Storyfeed::feed()->summary()->limit(2)->cursor($cursor)->get()->toArray();
        // An activity node is one activity; a row says how many it holds.
        $seen += array_sum(array_map(fn (array $item) => $item['count'] ?? 1, $page['items']));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe(Activity::count());
});

it('files a type-scoped phrase under the verb, as a row-backed headline is', function () {
    Story::for(Delivery::class)->verb('ride')->grouped(fn (GroupBuilder $group) => $group->summary('went on :count rides'));

    expect(Storyfeed::registeredAggregateGrammar())->toHaveKey('summary.ride', 'went on :count rides');
});

it('writes no curation stamp on a partition row, and curation never picks one', function () {
    config()->set('storyfeed.grouping.policy.min_object_members', 99);

    // With no aggregate axis eligible, curation falls back to repeat; it
    // must never fall through to a partition bucket instead.
    $activity = did(person('Will Byers'), 'ride', thing('Carousel'));

    expect(Storyfeed::uncuratedBuckets())->toContain('summary.day')
        ->and(Storyfeed::aggregateAxes())->not->toContain('summary.day')
        ->and(Grouping::query()->where('activity_id', $activity->getKey())->where('bucket', 'like', 'summary.%')->count())->toBe(4)
        ->and(Grouping::query()->where('activity_id', $activity->getKey())->where('bucket', 'like', 'summary.%')->whereNotNull('winner')->count())->toBe(0)
        ->and(Grouping::query()->where('activity_id', $activity->getKey())->where('winner', true)->value('bucket'))->toBe('repeat');
});

it('names the missing axis when an app replaced the registry without it', function () {
    Storyfeed::axes([Axis::make('repeat')->key('aa:aid:v:oa:ta:tid:d')->fallback()], merge: false);

    did(person('Will Byers'), 'ride', thing('Carousel'));

    expect(fn () => digest())->toThrow(FeedMisconfigured::class, 'summary(Period::Day) reads the [summary.day] axis');
});

it('gives a named party its own row, like any person', function () {
    foreach (range(1, 3) as $n) {
        Storyfeed::activity('process', thing("Payment {$n}"))->actor('Stripe')->publishedAt(now()->setTime(12, $n))->publish();
    }

    $row = digest()[0];

    expect($row['count'])->toBe(3)
        ->and($row['verb'])->toBe('process')
        ->and($row['actor']['label'])->toBe('Stripe')
        ->and($row['phrases'][0]['distinct']['objects'])->toBe(3);
});
