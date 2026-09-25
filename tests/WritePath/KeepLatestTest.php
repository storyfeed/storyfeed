<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story as BaseStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Replacement, declared on the verb only: `->keepLatest(per:, within:)`
 * (scratchpad 304 §6, as ruled 2026-09-24). The latest `published_at` wins,
 * whatever order the rows arrive in.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-24 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(StoryManifest::class)->delete();
});

function saved(Delivery $delivery, ?string $at = null, ?User $actor = null): Activity
{
    return Storyfeed::activity('save', $delivery)
        ->when($actor !== null, fn ($pending) => $pending->actor($actor))
        ->when($at !== null, fn ($pending) => $pending->publishedAt($at))
        ->publish();
}

/** @return list<int> */
function liveSaves(Delivery $delivery): array
{
    return Activity::query()->object($delivery)->verb('save')->orderBy('id')->pluck('id')->all();
}

describe('declaring it', function () {
    it('compiles to the roles and the window, most specific declaration winning', function () {
        Story::verb('save')->keepLatest();
        Story::for(Delivery::class)->verb('save')->keepLatest(per: ['actor', 'object'], within: '10 minutes');
        Story::verb('view')->keepLatest(per: 'actor');

        expect(Storyfeed::compiledStories()['keepLatest'])->toBe([
            '*.save' => ['per' => ['object'], 'within' => null],
            'delivery.save' => ['per' => ['actor', 'object'], 'within' => 'PT10M'],
            '*.view' => ['per' => ['actor'], 'within' => null],
        ])
            ->and(Storyfeed::keepLatest('delivery', 'save'))->toBe(['per' => ['actor', 'object'], 'within' => 'PT10M'])
            ->and(Storyfeed::keepLatest('customer', 'save'))->toBe(['per' => ['object'], 'within' => null])
            ->and(Storyfeed::keepLatest('customer', 'open'))->toBeNull();
    });

    it('declares it in the array form and on a Story class', function () {
        defineStories(
            Verb::make('delivery.save')->fill(['keepLatest' => true], 'delivery.save'),
            Verb::make('delivery.view')->fill(['keepLatest' => ['per' => ['object', 'actor']]], 'delivery.view'),
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

                public function keepLatest(): array
                {
                    return ['within' => '1 hour'];
                }
            }, null, 'update', 'a line'),
        );

        expect(Storyfeed::keepLatest('delivery', 'save'))->toBe(['per' => ['object'], 'within' => null])
            ->and(Storyfeed::keepLatest('delivery', 'view'))->toBe(['per' => ['actor', 'object'], 'within' => null])
            ->and(Storyfeed::keepLatest('customer', 'update'))->toBe(['per' => ['object'], 'within' => 'PT1H']);
    });

    it('refuses a role that is not one', function () {
        Story::verb('save')->keepLatest(per: ['object', 'author']);
    })->throws(InvalidArgumentException::class, "->keepLatest() on [*.save] was given per: 'author'");

    it('refuses a window that is not a positive interval', function () {
        Story::verb('save')->keepLatest(within: 'soon');
    })->throws(InvalidArgumentException::class, "->keepLatest() on [*.save] was given within: 'soon'");

    it('is a compiled part, so a request action must return the same policy', function () {
        $a = Verb::make('delivery.save')->keepLatest()->compiledParts();
        $b = Verb::make('delivery.save')->keepLatest(within: '1 hour')->compiledParts();

        expect($a['keepLatest'])->not->toBe($b['keepLatest']);
    });

    it('survives storyfeed:cache as scalars', function () {
        Story::verb('save')->keepLatest(per: ['object', 'actor'], within: '10 minutes');

        Artisan::call('storyfeed:cache');
        Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

        expect(Storyfeed::keepLatest(null, 'save'))->toBe(['per' => ['actor', 'object'], 'within' => 'PT10M']);
    });

    it('shows in storyfeed:list', function () {
        Story::verb('save')->keepLatest(per: ['object', 'actor'], within: '10 minutes');
        Story::verb('view');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

        expect($rows['save']['keep_latest'])->toBe('per actor, object within 10 minutes')
            ->and($rows['view']['keep_latest'])->toBeNull();
    });
});

describe('what a publish supersedes', function () {
    it('keeps every row of a verb that declares nothing', function () {
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery);
        saved($delivery);

        expect(liveSaves($delivery))->toHaveCount(2);
    });

    it('collapses repeated same-object same-verb activities', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery);
        saved($delivery);
        $latest = saved($delivery);

        expect(liveSaves($delivery))->toBe([$latest->id]);
    });

    it('does not touch activities with a different verb or object', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        $other = Delivery::create(['tracking_number' => 'TN-2']);

        Storyfeed::activity('create', $delivery)->publish();
        saved($other);
        saved($delivery);

        expect(Activity::query()->count())->toBe(3);
    });

    it('keys on the roles per: names', function () {
        Story::verb('save')->keepLatest(per: ['object', 'actor']);
        $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
        $bo = User::create(['name' => 'Bo', 'email' => 'bo@example.com']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery, actor: $ann);
        $bos = saved($delivery, actor: $bo);
        $anns = saved($delivery, actor: $ann);

        expect(liveSaves($delivery))->toBe([$bos->id, $anns->id]);
    });

    it('never treats two unknown actors as one person', function () {
        Story::verb('save')->keepLatest(per: ['object', 'actor']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        Storyfeed::anonymous()->verb('save', $delivery)->publish();
        Storyfeed::anonymous()->verb('save', $delivery)->publish();

        expect(liveSaves($delivery))->toHaveCount(2);
    });

    it('supersedes only rows within the window of the new one', function () {
        Story::verb('save')->keepLatest(within: '10 minutes');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $morning = saved($delivery, '2026-09-24 09:00:00');
        saved($delivery, '2026-09-24 11:52:00');
        $noon = saved($delivery, '2026-09-24 12:00:00');

        expect(liveSaves($delivery))->toBe([$morning->id, $noon->id]);
    });

    it('soft-deletes superseded rows by default: history is kept, the feed forgets', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $first = saved($delivery);
        $latest = saved($delivery);

        $trashed = Activity::query()->withTrashed()->whereKey($first->id)->first();

        expect($trashed)->not->toBeNull()
            ->and($trashed->trashed())->toBeTrue()
            ->and(liveSaves($delivery))->toBe([$latest->id])
            // The participant index is over rows that exist; the superseded row
            // must not be findable by the entity it involved.
            ->and(DB::table(SyncParticipants::table())->where('activity_id', $first->id)->count())->toBe(0)
            // Grouping rows stay: inert, and prune sweeps them with the activity.
            ->and(Grouping::query()->where('activity_id', $first->id)->count())->toBeGreaterThan(0);
    });
});

describe('the latest published_at wins, whatever the arrival order', function () {
    it('supersedes the older row when rows arrive in order', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery, '2026-09-20 09:00:00');
        $newer = saved($delivery, '2026-09-21 09:00:00');

        expect(liveSaves($delivery))->toBe([$newer->id]);
    });

    it('stores a backdated row already superseded when it arrives after a newer one', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $newer = saved($delivery, '2026-09-21 09:00:00');
        $older = saved($delivery, '2026-09-20 09:00:00');

        expect(liveSaves($delivery))->toBe([$newer->id])
            ->and($older->exists)->toBeTrue()
            ->and($older->trashed())->toBeTrue()
            // Born superseded: never entered the feed, so nothing points at it.
            ->and(Grouping::query()->where('activity_id', $older->id)->count())->toBe(0)
            ->and(DB::table(SyncParticipants::table())->where('activity_id', $older->id)->count())->toBe(0)
            ->and(Activity::query()->withTrashed()->whereKey($older->id)->exists())->toBeTrue();
    });

    it('ends on the same live row for a backfill replayed in any order', function (array $days) {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        foreach ($days as $day) {
            saved($delivery, "2026-09-{$day} 09:00:00");
        }

        $live = Activity::query()->object($delivery)->verb('save')->sole();

        expect($live->published_at->toDateString())->toBe('2026-09-22')
            ->and(Activity::query()->withTrashed()->count())->toBe(3);
    })->with([
        'ascending' => [[20, 21, 22]],
        'descending' => [[22, 21, 20]],
        'shuffled' => [[21, 22, 20]],
    ]);

    it('does not announce a row born superseded', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        $announced = 0;
        Event::listen(ActivityPublished::class, function () use (&$announced) {
            $announced++;
        });

        saved($delivery, '2026-09-21 09:00:00');
        saved($delivery, '2026-09-20 09:00:00');

        expect($announced)->toBe(1);
    });

    it('lets a tie go to the row being published', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery, '2026-09-21 09:00:00');
        $second = saved($delivery, '2026-09-21 09:00:00');

        expect(liveSaves($delivery))->toBe([$second->id]);
    });

    it('stores a backdated row superseded only by a newer row within the window', function () {
        Story::verb('save')->keepLatest(within: '10 minutes');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $noon = saved($delivery, '2026-09-24 12:00:00');
        $burst = saved($delivery, '2026-09-24 11:55:00');
        $morning = saved($delivery, '2026-09-24 09:00:00');

        expect($burst->trashed())->toBeTrue()
            ->and(liveSaves($delivery))->toBe([$noon->id, $morning->id]);
    });
});

describe('storyfeed.keep_latest.delete', function () {
    it('hard-deletes superseded rows and everything pointing at them under force', function () {
        config()->set('storyfeed.keep_latest.delete', 'force');
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $first = saved($delivery);
        $second = saved($delivery);
        $latest = saved($delivery);

        expect(Activity::query()->withTrashed()->pluck('id')->all())->toBe([$latest->id])
            ->and(Grouping::query()->whereIn('activity_id', [$first->id, $second->id])->count())->toBe(0)
            ->and(DB::table(SyncParticipants::table())->whereIn('activity_id', [$first->id, $second->id])->count())->toBe(0)
            ->and(Grouping::query()->where('activity_id', $latest->id)->count())->toBeGreaterThan(0)
            ->and(DB::table(SyncParticipants::table())->where('activity_id', $latest->id)->count())->toBeGreaterThan(0);
    });

    it('writes nothing for a backdated row under force', function () {
        config()->set('storyfeed.keep_latest.delete', 'force');
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $newer = saved($delivery, '2026-09-21 09:00:00');
        $older = saved($delivery, '2026-09-20 09:00:00');

        expect($older->exists)->toBeFalse()
            ->and(Activity::query()->withTrashed()->pluck('id')->all())->toBe([$newer->id]);
    });

    it('refuses an unknown value instead of guessing, on the first publish', function () {
        config()->set('storyfeed.keep_latest.delete', 'hard');
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        // Validated even when nothing is there to supersede yet: a typo that only
        // bit on the SECOND publish would ship, and surface in production.
        expect(fn () => saved($delivery))
            ->toThrow(InvalidArgumentException::class, 'storyfeed.keep_latest.delete')
            ->and(Activity::query()->withTrashed()->count())->toBe(0);
    });

    it('ignores the setting for a verb that declares nothing', function () {
        config()->set('storyfeed.keep_latest.delete', 'hard');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery);

        expect(Activity::query()->count())->toBe(1);
    });
});

describe('the doctor', function () {
    it('names superseded rows on a verb with no declaration, and --stubs prints it', function () {
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        $first = saved($delivery, '2026-09-20 09:00:00');
        saved($delivery, '2026-09-21 09:00:00');
        $first->delete();

        $finding = Storyfeed::doctor(['keep_latest'])->withCode('keep_latest.undeclared')->sole();

        expect($finding->subject)->toBe(['type' => 'delivery', 'verb' => 'save', 'count' => 1])
            ->and($finding->fix?->definition()['code'])->toBe("Story::for(Delivery::class)->verb('save')->keepLatest();")
            // Only routes/feed.php can declare it, so the snippet is the line.
            ->and($finding->fix?->snippet())->toBe("Story::for(Delivery::class)->verb('save')->keepLatest();");

        Artisan::call('storyfeed:doctor', ['--only' => ['keep_latest'], '--stubs' => true]);

        expect(Artisan::output())->toContain("Story::for(Delivery::class)->verb('save')->keepLatest();");
    });

    it('is quiet once the verb declares it', function () {
        Story::verb('save')->keepLatest();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        saved($delivery);
        saved($delivery);

        expect(Storyfeed::doctor(['keep_latest'])->all())->toBeEmpty();
    });

    it('does not mistake a row deleted on its own for a superseded one', function () {
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        saved($delivery, '2026-09-20 09:00:00');
        saved($delivery, '2026-09-21 09:00:00')->delete();

        expect(Storyfeed::doctor(['keep_latest'])->all())->toBeEmpty();
    });

    it('warns when a declared verb has superseded rows and live duplicates on one key', function () {
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        // Published before the declaration: two live rows on one key.
        saved($delivery, '2026-09-20 09:00:00');
        saved($delivery, '2026-09-21 09:00:00');

        Story::verb('save')->keepLatest();

        // And after it, on a key whose history it superseded — then a row
        // written without publishing, as a raw import would.
        saved($delivery, '2026-09-22 09:00:00');
        $row = Activity::query()->object($delivery)->verb('save')->sole()->replicate(['uid']);
        $row->uid = (string) str()->ulid();
        $row->published_at = Carbon::parse('2026-09-22 10:00:00');
        $row->save();

        $finding = Storyfeed::doctor(['keep_latest'])->withCode('keep_latest.split')->sole();

        expect($finding->subject)->toBe(['type' => 'delivery', 'verb' => 'save', 'keys' => 1])
            ->and(Storyfeed::doctor(['keep_latest'])->has('keep_latest.undeclared'))->toBeFalse();
    });

    it('does not call live rows outside a window a split', function () {
        Story::verb('save')->keepLatest(within: '10 minutes');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        saved($delivery, '2026-09-24 09:00:00');
        saved($delivery, '2026-09-24 11:55:00');
        saved($delivery, '2026-09-24 12:00:00');

        expect(Storyfeed::doctor(['keep_latest'])->all())->toBeEmpty();
    });
});
