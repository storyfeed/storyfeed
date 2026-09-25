<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story as BaseStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Support\CurationWindow;
use Storyfeed\Tests\Fixtures\Stories\PeriodicDeliveryStory;
use Storyfeed\Tests\Fixtures\Stories\TallyDelivery;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Grouping periods, declared per verb (scratchpad 304 §6b, ruled 2026-09-24
 * as Q8 + Q9): the period is the VALUE of the Day segment, so a verb that
 * declares nothing hashes byte for byte as before, and a week starts on
 * Monday everywhere.
 */

afterEach(function () {
    Carbon::setTestNow();
    Carbon::setLocale('en');
    app(StoryManifest::class)->delete();
});

/** @return array<string, string> */
function periodHashes(string $verb, string $at): array
{
    $hashes = app(config('storyfeed.grouping.strategy'))->hashes(new Activity([
        'actor_type' => 'user', 'actor_id' => 7,
        'verb' => $verb,
        'object_type' => 'delivery', 'object_id' => 42,
        'target_type' => 'customer', 'target_id' => 9,
        'published_at' => Carbon::parse($at),
    ]));

    ksort($hashes);

    return $hashes;
}

function periodOf(string $verb, string $at): string
{
    return explode(':', periodHashes($verb, $at)['targets'])[3];
}

function repeatHash(Activity $activity): ?string
{
    return Grouping::query()->where('activity_id', $activity->getKey())->where('bucket', 'repeat')->value('hash');
}

describe('the value of the Day segment', function () {
    it('leaves a day hash byte-identical, declared daily or not declared at all', function () {
        $frozen = [
            'actors' => 'revise:customer:9:2026-08-12',
            'object' => 'user:7:revise:delivery:42:2026-08-12',
            'repeat' => 'user:7:revise:delivery:customer:9:2026-08-12',
            'targets' => 'user:7:revise:2026-08-12',
        ];

        expect(periodHashes('revise', '2026-08-12 09:15:00'))->toBe($frozen);

        Story::verb('revise')->headline(':actor revised :object');
        expect(periodHashes('revise', '2026-08-12 09:15:00'))->toBe($frozen);

        Story::verb('open')->groupedDaily();
        expect(periodHashes('open', '2026-08-12 09:15:00')['targets'])->toBe('user:7:open:2026-08-12');
    });

    it('cuts a week on Monday, Sunday night and Monday morning apart', function () {
        Story::verb('publish')->groupedWeekly();

        expect(periodOf('publish', '2026-09-21 00:00:00'))->toBe('2026-W39') // Monday
            ->and(periodOf('publish', '2026-09-27 23:59:59'))->toBe('2026-W39') // Sunday
            ->and(periodOf('publish', '2026-09-28 00:00:00'))->toBe('2026-W40'); // Monday
    });

    it('cuts the ISO week whatever the locale says a week starts on', function () {
        Story::verb('publish')->groupedWeekly();

        // en_US starts its week on Sunday, which is what startOfWeek() follows.
        Carbon::setLocale('en_US');

        expect(Carbon::parse('2026-09-27')->startOfWeek()->isSunday())->toBeTrue()
            ->and(periodOf('publish', '2026-09-27 12:00:00'))->toBe('2026-W39');
    });

    it('names a week by its ISO year across New Year', function () {
        Story::verb('publish')->groupedWeekly();

        expect(periodOf('publish', '2026-12-31 12:00:00'))->toBe('2026-W53')
            ->and(periodOf('publish', '2027-01-01 12:00:00'))->toBe('2026-W53')
            ->and(periodOf('publish', '2027-01-04 00:00:00'))->toBe('2027-W01');
    });

    it('cuts a month on the first', function () {
        Story::verb('invoice')->groupedMonthly();

        expect(periodOf('invoice', '2026-09-01 00:00:00'))->toBe('2026-09')
            ->and(periodOf('invoice', '2026-09-30 23:59:59'))->toBe('2026-09')
            ->and(periodOf('invoice', '2026-10-01 00:00:00'))->toBe('2026-10');
    });

    it('cuts an hour on the clock', function () {
        Story::verb('open')->groupedHourly();

        // The whole key: the hour's value holds a `T`, never a `:`.
        expect(periodHashes('open', '2026-09-23 14:59:00')['targets'])->toBe('user:7:open:2026-09-23T14')
            ->and(periodOf('open', '2026-09-23 15:01:00'))->toBe('2026-09-23T15');
    });
});

describe('declaring it', function () {
    it('resolves on the type → verb ladder, the most specific declaration winning', function () {
        Story::fallback()->groupedWeekly();
        Story::verb('open')->groupedHourly();
        Story::for(Delivery::class)->verb('open')->groupedDaily();

        expect(Storyfeed::compiledStories()['periods'])->toBe([
            '*.*' => 'week',
            '*.open' => 'hour',
            'delivery.open' => 'day',
        ])
            ->and(Storyfeed::period('delivery', 'open'))->toBe(Period::Day)
            ->and(Storyfeed::period('customer', 'open'))->toBe(Period::Hour)
            ->and(Storyfeed::period('customer', 'place'))->toBe(Period::Week)
            ->and(Storyfeed::period(null, 'place'))->toBe(Period::Week);
    });

    it('is a day when nothing declares one', function () {
        expect(Storyfeed::period('delivery', 'open'))->toBe(Period::Day)
            ->and(Storyfeed::compiledStories()['periods'])->toBe([]);
    });

    it('declares it with groupedPer(), in the array form and on a Story class', function () {
        defineStories(
            Verb::make('delivery.save')->groupedPer(Period::Month),
            Verb::make('delivery.view')->fill(['groupedPer' => 'hour'], 'delivery.view'),
            Verb::fromStory(new class extends BaseStory
            {
                public string|array|null $objectType = 'customer';

                public \Storyfeed\Contracts\FeedVerb|\BackedEnum|string|null $verb = 'update';

                public function toFeedActivity(): ?PendingActivity
                {
                    return $this->activity();
                }

                public function headline(): string
                {
                    return ':actor updated :object';
                }

                public function period(): Period
                {
                    return Period::Week;
                }
            }, null, 'update', 'a line'),
        );

        expect(Storyfeed::period('delivery', 'save'))->toBe(Period::Month)
            ->and(Storyfeed::period('delivery', 'view'))->toBe(Period::Hour)
            ->and(Storyfeed::period('customer', 'update'))->toBe(Period::Week);
    });

    it('declares it in an invokable class and on a resource class, through Verb', function () {
        Story::for(Delivery::class)->verb('tally', TallyDelivery::class);
        Story::resource(Delivery::class, PeriodicDeliveryStory::class);

        expect(Storyfeed::period('delivery', 'tally'))->toBe(Period::Week)
            ->and(Storyfeed::period('delivery', 'scan'))->toBe(Period::Hour)
            ->and(Storyfeed::period('delivery', 'invoice'))->toBe(Period::Month)
            ->and(Storyfeed::period('delivery', 'note'))->toBe(Period::Day);
    });

    it('refuses a string that is not a period', function () {
        Story::verb('open')->groupedPer('fortnight');
    })->throws(InvalidArgumentException::class, "->groupedPer() on [*.open] was given 'fortnight', which is not a period. Give one of 'hour', 'day', 'week', 'month'.");

    it('is a compiled part, so a request action must return the same period', function () {
        $daily = Verb::make('delivery.open')->compiledParts();
        $weekly = Verb::make('delivery.open')->groupedWeekly()->compiledParts();

        expect($daily['period'])->not->toBe($weekly['period']);
    });

    it('survives storyfeed:cache as a scalar', function () {
        Story::verb('publish')->groupedWeekly();

        Artisan::call('storyfeed:cache');
        $manifest = app(StoryManifest::class)->read();

        expect($manifest['periods'])->toBe(['*.publish' => 'week']);

        Storyfeed::useCompiledStories($manifest);

        expect(Storyfeed::period(null, 'publish'))->toBe(Period::Week);
    });

    it('shows in storyfeed:list, its own or the one the ladder gives it', function () {
        Story::fallback()->groupedMonthly();
        Story::verb('publish')->groupedWeekly();
        Story::verb('open');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true))->keyBy(fn (array $row) => "{$row['type']}.{$row['verb']}");

        expect($rows['*.publish']['period'])->toBe('week')
            ->and($rows['*.open']['period'])->toBe('month')
            ->and($rows['*.*']['period'])->toBe('month');

        Artisan::call('storyfeed:list');

        expect(Artisan::output())->toContain('Period')->toContain('week');
    });
});

describe('publishing', function () {
    it('groups a weekly verb across the week and a daily verb per day, side by side', function () {
        Story::verb('publish')->groupedWeekly();

        $actor = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $publish = fn (string $verb, string $at) => Storyfeed::activity($verb, $delivery)->actor($actor)->publishedAt($at)->publish();

        $monday = $publish('publish', '2026-09-21 09:00:00');
        $wednesday = $publish('publish', '2026-09-23 09:00:00');
        $nextMonday = $publish('publish', '2026-09-28 09:00:00');
        $openedMonday = $publish('open', '2026-09-21 09:00:00');
        $openedWednesday = $publish('open', '2026-09-23 09:00:00');

        expect(repeatHash($monday))->toBe(repeatHash($wednesday))
            ->and(repeatHash($monday))->toEndWith(':2026-W39')
            ->and(repeatHash($nextMonday))->toEndWith(':2026-W40')
            ->and(repeatHash($openedMonday))->toEndWith(':2026-09-21')
            ->and(repeatHash($openedWednesday))->toEndWith(':2026-09-23');
    });
});

describe('the curation look-back', function () {
    it('is the widest declared period, and only for the verbs that declare it', function () {
        expect(CurationWindow::widest(2))->toBe(2);

        Story::verb('open')->groupedHourly();
        Story::verb('publish')->groupedWeekly();
        Story::for(Delivery::class)->verb('invoice')->groupedMonthly();

        expect(CurationWindow::wider(2))->toBe(['*.publish' => 8, 'delivery.invoice' => 32])
            ->and(CurationWindow::widest(2))->toBe(32)
            ->and(CurationWindow::widest(40))->toBe(40);
    });

    it('reaches a weekly verb’s open group from the scheduled window, and no further for a daily one', function () {
        Carbon::setTestNow('2026-09-27 12:00:00'); // a Sunday

        Story::verb('publish')->groupedWeekly();

        $actor = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        // Five days old: inside the week, outside two days.
        $published = Storyfeed::activity('publish', $delivery)->actor($actor)->publishedAt('2026-09-22 09:00:00')->publish();
        $opened = Storyfeed::activity('open', $delivery)->actor($actor)->publishedAt('2026-09-22 09:00:00')->publish();

        Grouping::query()->update(['winner' => null]);

        Artisan::call('storyfeed:curate', ['--window' => 2]);

        $stamped = fn (Activity $activity) => Grouping::query()->where('activity_id', $activity->getKey())->where('winner', true)->exists();

        expect($stamped($published))->toBeTrue()
            ->and($stamped($opened))->toBeFalse();
    });

    it('agrees with the doctor: a weekly verb inside its week is reachable', function () {
        Carbon::setTestNow('2026-09-27 12:00:00');

        Story::verb('publish')->groupedWeekly();

        $actor = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        Storyfeed::activity('publish', $delivery)->actor($actor)->publishedAt('2026-09-22 09:00:00')->publish();
        Grouping::query()->update(['winner' => null]);

        $finding = collect(Storyfeed::doctor(['grouping'])->all())->first();

        expect($finding->code)->toBe('grouping.uncurated')
            ->and($finding->subject)->toMatchArray(['unreachable' => false]);

        // A daily verb as old is out of reach, and the message says why.
        Storyfeed::activity('open', $delivery)->actor($actor)->publishedAt('2026-09-22 09:00:00')->publish();
        Grouping::query()->update(['winner' => null]);

        $finding = collect(Storyfeed::doctor(['grouping'])->all())->first();

        expect($finding->subject)->toMatchArray(['unreachable' => true])
            ->and($finding->message)->toContain('looks back 2 days (8 for a verb grouped per week or month)');
    });
});
