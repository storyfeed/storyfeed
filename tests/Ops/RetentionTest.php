<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Checks\Retention;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Snapshot;
use Storyfeed\Stories\Story as BaseStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Retention declared per verb: ->keepFor() and ->keepForever(), swept by
 * storyfeed:prune (scratchpad 311). A pruned group shrinks, a snapshot only
 * pruned rows named goes with them, and --pretend says so first.
 */

beforeEach(function () {
    // Late evening, so rows hours apart share a day's clusters.
    Carbon::setTestNow('2026-09-23 22:00:00');
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
});

afterEach(function () {
    Carbon::setTestNow();
    app(StoryManifest::class)->delete();
});

function aged(string $verb, CarbonInterval|string $ago, mixed $object = null, ?User $actor = null): Activity
{
    $ago = is_string($ago) ? CarbonInterval::make($ago) : $ago;

    return Storyfeed::activity()
        ->actor($actor ?? test()->sally)
        ->verb($verb, $object)
        ->publishedAt(now()->sub($ago))
        ->publish();
}

function remaining(): array
{
    return Activity::query()->withTrashed()->orderBy('id')->pluck('verb')->all();
}

function tombstoneState(Delivery $delivery): array
{
    $tombstone = FeedTombstone::query()->where('model_type', 'delivery')->where('model_id', (string) $delivery->id)->sole();

    return [
        $tombstone->restorable,
        Activity::query()->where('object_type', FeedTombstone::MORPH_ALIAS)->where('object_id', $tombstone->id)->count(),
        Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->where('model_id', $tombstone->id)->count(),
        Snapshot::query()->where('model_type', 'delivery')->count(),
    ];
}

describe('declaring a window', function () {
    it('compiles keepFor() to an ISO 8601 duration, and keepForever() to forever', function () {
        Story::verb('view')->keepFor('30 days');
        Story::for(Delivery::class)->verb('open')->keepFor(CarbonInterval::months(6));
        Story::verb('sign')->keepForever();

        expect(Storyfeed::compiledStories()['retention'])->toBe([
            '*.view' => 'P30D',
            'delivery.open' => 'P6M',
            '*.sign' => Verb::FOREVER,
        ])
            ->and(Storyfeed::retention('delivery', 'view'))->toBe('P30D')
            ->and(Storyfeed::retention('customer', 'open'))->toBeNull();
    });

    it('declares a window in the array form and on a Story class', function () {
        Storyfeed::stories([
            'delivery.view' => ['keepFor' => '2 weeks'],
            'delivery.sign' => ['keepForever' => true],
            Verb::fromStory(new class extends BaseStory
            {
                public string|array|null $objectType = 'customer';

                public \Storyfeed\Contracts\FeedVerb|\BackedEnum|string|null $verb = 'open';

                public function headline(): string
                {
                    return ':actor opened :object';
                }

                public function keepFor(): string
                {
                    return '90 days';
                }
            }),
        ]);

        expect(Storyfeed::retention('delivery', 'view'))->toBe('P14D')
            ->and(Storyfeed::retention('delivery', 'sign'))->toBe(Verb::FOREVER)
            ->and(Storyfeed::retention('customer', 'open'))->toBe('P90D');
    });

    it('refuses a window that is not a positive interval, naming the verb', function (string $window) {
        Story::verb('view')->keepFor($window);
    })->with(['nonsense', '0 days', '-3 days'])
        ->throws(InvalidArgumentException::class, '->keepFor() on [*.view]');

    it('is a compiled part, so a request action must return the same window', function () {
        $a = Verb::make('delivery.view')->keepFor('30 days')->compiledParts();
        $b = Verb::make('delivery.view')->keepFor('31 days')->compiledParts();

        expect($a['retention'])->not->toBe($b['retention']);
    });

    it('survives storyfeed:cache', function () {
        Story::verb('view')->keepFor('30 days');
        Story::verb('sign')->keepForever();

        Artisan::call('storyfeed:cache');
        Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

        expect(Storyfeed::retention(null, 'view'))->toBe('P30D')
            ->and(Storyfeed::retention(null, 'sign'))->toBe(Verb::FOREVER);
    });
});

describe('which rows a prune takes', function () {
    it('prunes a verb on its own window with no global one set', function () {
        Story::verb('view')->keepFor('30 days');

        aged('view', '40 days');
        aged('view', '10 days');
        aged('sign', '400 days');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(remaining())->toBe(['view', 'sign']);
    });

    it('lets a verb\'s window override the global one, in both directions', function () {
        config()->set('storyfeed.prune.after_days', 90);
        Story::verb('view')->keepFor('7 days');
        Story::verb('comment')->keepFor('1 year');

        aged('view', '10 days');
        aged('comment', '100 days');
        aged('ping', '100 days');
        aged('ping', '10 days');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(remaining())->toBe(['comment', 'ping']);
    });

    it('keeps a keepForever() verb whatever the global window says', function () {
        config()->set('storyfeed.prune.after_days', 30);
        Story::verb('sign')->keepForever();

        aged('sign', '5 years');
        aged('ping', '60 days');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(remaining())->toBe(['sign']);
    });

    it('overrides only the global window with --days', function () {
        Story::verb('comment')->keepFor('1 year');

        aged('comment', '100 days');
        aged('ping', '10 days');

        $this->artisan('storyfeed:prune', ['--days' => 5])->assertSuccessful();

        expect(remaining())->toBe(['comment']);
    });

    it('resolves a window on the type ladder', function () {
        config()->set('storyfeed.prune.after_days', 30);
        Story::for(Delivery::class)->verb('open')->keepForever();

        aged('open', '60 days', Delivery::create(['tracking_number' => 'TN-1']));
        aged('open', '60 days', Customer::create(['name' => 'Acme']));
        aged('open', '60 days');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(Activity::query()->pluck('object_type')->all())->toBe(['delivery']);
    });

    it('keeps the window of the type a tombstoned object was', function () {
        Story::verb('open')->keepFor('30 days');
        Story::for(Delivery::class)->verb('open')->keepForever();

        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        aged('open', '60 days', $delivery);
        aged('open', '60 days', Customer::create(['name' => 'Acme']));
        $delivery->forceDelete();

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(Activity::query()->sole()->object_type)->toBe(FeedTombstone::MORPH_ALIAS);
    });

    it('still prunes expired soft-deleted rows', function () {
        Story::verb('view')->keepFor('30 days');

        aged('view', '60 days')->delete();

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(Activity::query()->withTrashed()->count())->toBe(0);
    });
});

describe('what a pruned group reads as', function () {
    it('shrinks: the group reads as what remains', function () {
        Story::verb('view')->keepFor('12 hours');

        foreach (range(1, 9) as $i) {
            aged('view', '20 hours', Delivery::create(['tracking_number' => "Old-{$i}"]));
        }

        foreach (range(1, 3) as $i) {
            aged('view', '1 hour', Delivery::create(['tracking_number' => "New-{$i}"]));
        }

        expect(Storyfeed::feed()->get()->toArray()['items'][0]['count'])->toBe(12);

        $token = SyncToken::current();

        $this->artisan('storyfeed:prune')->assertSuccessful();

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0]['kind'])->toBe('group')
            ->and($items[0]['count'])->toBe(3)
            ->and(collect($items[0]['sample']['objects'])->pluck('label')->every(fn ($label) => str_starts_with($label, 'Delivery #New-')))->toBeTrue()
            ->and(SyncToken::current())->not->toBe($token);
    });

    it('re-decides what remains, so a group below its threshold reads as singles', function () {
        Story::verb('view')->keepFor('12 hours');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        foreach (['Ada', 'Ben', 'Cy'] as $name) {
            aged('view', '20 hours', $delivery, User::create(['name' => $name, 'email' => "{$name}@example.com"]));
        }

        aged('view', '1 hour', $delivery);

        $this->artisan('storyfeed:prune')->assertSuccessful();

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0]['kind'])->toBe('activity')
            ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0);
    });

    it('disappears when every member is pruned, leaving no empty node', function () {
        Story::verb('view')->keepFor('12 hours');

        foreach (range(1, 3) as $i) {
            aged('view', '20 hours', Delivery::create(['tracking_number' => "Old-{$i}"]));
        }

        aged('ping', '20 hours');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0]['verb'])->toBe('ping')
            ->and(Grouping::query()->whereNotIn('activity_id', Activity::query()->withTrashed()->select('id'))->count())->toBe(0);
    });

    it('does not move sync_token when no group changed', function () {
        Story::verb('view')->keepFor('30 days');
        SyncToken::bump();
        $token = SyncToken::current();

        aged('view', '40 days', Delivery::create(['tracking_number' => 'TN-1']));
        aged('ping', '1 day');

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(remaining())->toBe(['ping'])
            ->and(SyncToken::current())->toBe($token);
    });

    it('releases the members of a pruned composite parent, so none is hidden', function () {
        Story::verb('upload')->keepFor('30 days');
        Story::for(Delivery::class)->verb('upload')->keepForever();

        $parent = Storyfeed::activity('upload')
            ->actor($this->sally)
            ->objects([Delivery::create(['tracking_number' => 'A']), Delivery::create(['tracking_number' => 'B'])])
            ->publishedAt(now()->subDays(40))
            ->publish();

        $this->artisan('storyfeed:prune')->assertSuccessful();

        $items = Storyfeed::feed()->get()->toArray()['items'];

        expect(Activity::query()->find($parent->id))->toBeNull()
            ->and(Activity::query()->count())->toBe(2)
            ->and(Grouping::query()->where('bucket', 'composite')->count())->toBe(0)
            ->and(collect($items)->sum(fn (array $item) => $item['count'] ?? 1))->toBe(2);
    });
});

describe('what else a prune takes', function () {
    it('sweeps a snapshot only pruned rows named, and keeps one still named', function () {
        Story::verb('view')->keepFor('30 days');
        $gone = Delivery::create(['tracking_number' => 'Gone']);
        $kept = Delivery::create(['tracking_number' => 'Kept']);
        $untouched = Delivery::create(['tracking_number' => 'Untouched']);

        aged('view', '40 days', $gone);
        aged('view', '40 days', $kept);
        aged('comment', '40 days', $kept);

        expect(Snapshot::query()->where('model_type', 'delivery')->count())->toBe(3);

        $this->artisan('storyfeed:prune')
            ->expectsOutputToContain('Pruned 2 activities, 1 snapshots and 0 tombstones.')
            ->assertSuccessful();

        expect(Snapshot::query()->where('model_type', 'delivery')->orderBy('model_id')->pluck('model_id')->all())
            ->toBe([$kept->id, $untouched->id])
            // The actor is still named by the comment.
            ->and(Snapshot::query()->where('model_type', $this->sally->getMorphClass())->count())->toBe(1);
    });

    it('keeps a snapshot a soft-deleted row still names, though it has no participant rows', function () {
        Story::verb('view')->keepFor('30 days');
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        aged('view', '40 days', $delivery);
        $superseded = aged('confirm', '1 day', $delivery);
        $superseded->delete();
        SyncParticipants::forget($superseded->id);

        $this->artisan('storyfeed:prune')->assertSuccessful();

        expect(Snapshot::query()->where('model_type', 'delivery')->where('model_id', $delivery->id)->exists())->toBeTrue();
    });

    it('sweeps a tombstone only pruned rows named, with its snapshot', function () {
        Delivery::$tombstone = fn ($tombstone) => $tombstone->keepLabel();
        Story::verb('view')->keepFor('30 days');

        $gone = Delivery::create(['tracking_number' => 'Gone']);
        $kept = Delivery::create(['tracking_number' => 'Kept']);
        aged('view', '40 days', $gone);
        aged('view', '40 days', $kept);
        aged('comment', '1 day', $kept);
        $gone->forceDelete();
        $kept->forceDelete();

        expect(FeedTombstone::query()->count())->toBe(2);

        $this->artisan('storyfeed:prune')->assertSuccessful();

        $survivor = FeedTombstone::query()->sole();

        expect($survivor->model_id)->toBe((string) $kept->id)
            ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->pluck('model_id')->all())->toBe([$survivor->id]);
    })->after(fn () => Delivery::$tombstone = null);

    it('leaves the same state behind when forgetWhenMissing() takes the last row', function () {
        Story::verb('confirm')->forgetWhenMissing();
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);
        $customer = Customer::create(['name' => 'Only here']);

        Storyfeed::activity()->actor($this->sally)->verb('confirm', $delivery)->for($customer)->publish();

        $delivery->forceDelete();

        expect(Activity::query()->withTrashed()->count())->toBe(0)
            ->and(FeedTombstone::query()->count())->toBe(0)
            ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->count())->toBe(0)
            ->and(Snapshot::query()->where('model_type', 'customer')->count())->toBe(0);
    });
});

it('keeps no tombstone for a deleted model no activity names', function () {
    Delivery::create(['tracking_number' => 'Never in the feed'])->forceDelete();
    Delivery::create(['tracking_number' => 'Trashed, never in the feed'])->delete();

    expect(FeedTombstone::query()->count())->toBe(0)
        ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->count())->toBe(0);
});

/*
 * The sweep after a model event is skipped when the event has just moved
 * activities onto the tombstone: they name it, so it could delete nothing
 * (todo 1359). On Postgres each sweep statement costs ~0.7ms, nearly all of
 * it planning, so the skip is measured in sweeps run.
 */
describe('the sweep after a model event', function () {
    beforeEach(function () {
        $this->sweeps = 0;
        $table = (new FeedTombstone)->getTable();

        DB::listen(function ($query) use ($table) {
            if (str_starts_with(strtolower($query->sql), 'delete from') && str_contains($query->sql, $table) && str_contains($query->sql, 'referencing')) {
                $this->sweeps++;
            }
        });
    });

    it('runs none for a soft delete of a model in the feed', function () {
        $delivery = Delivery::create(['tracking_number' => 'In the feed']);
        aged('confirm', '1 hour', $delivery);

        $delivery->delete();

        expect($this->sweeps)->toBe(0)
            ->and(tombstoneState($delivery))->toBe([true, 1, 1, 0]);
    });

    it('runs one for a force delete of a model in the feed', function () {
        $delivery = Delivery::create(['tracking_number' => 'In the feed']);
        aged('confirm', '1 hour', $delivery);

        $delivery->forceDelete();

        expect($this->sweeps)->toBe(1)
            ->and(tombstoneState($delivery))->toBe([false, 1, 1, 0]);
    });

    it('runs two for a force delete of a model already trashed', function () {
        $delivery = Delivery::create(['tracking_number' => 'In the feed']);
        aged('confirm', '1 hour', $delivery);
        $delivery->delete();
        $this->sweeps = 0;

        $delivery->forceDelete();

        // The soft delete moved everything, so neither event moves anything
        // and neither can tell the tombstone is named without asking.
        expect($this->sweeps)->toBe(2)
            ->and(tombstoneState($delivery))->toBe([false, 1, 1, 0]);
    });

    it('still sweeps a tombstone for a model never in the feed', function () {
        Delivery::create(['tracking_number' => 'Trashed, never in the feed'])->delete();

        expect($this->sweeps)->toBe(1);

        Delivery::create(['tracking_number' => 'Never in the feed'])->forceDelete();

        expect($this->sweeps)->toBe(3)
            ->and(FeedTombstone::query()->count())->toBe(0)
            ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->count())->toBe(0);
    });

    it('still sweeps a tombstone whose activities were deleted before the force delete', function () {
        $delivery = Delivery::create(['tracking_number' => 'Emptied']);
        aged('confirm', '1 hour', $delivery);
        $delivery->delete();

        DB::table((new Activity)->getTable())->delete();
        $delivery->forceDelete();

        expect(FeedTombstone::query()->count())->toBe(0)
            ->and(Snapshot::query()->where('model_type', FeedTombstone::MORPH_ALIAS)->count())->toBe(0);
    });
});

describe('--pretend', function () {
    it('reports per verb, with the snapshots and tombstones, and deletes nothing', function () {
        Story::verb('view')->keepFor('30 days');
        Story::verb('open')->keepFor('30 days');
        $gone = Delivery::create(['tracking_number' => 'Gone']);

        aged('view', '40 days', $gone);
        aged('view', '40 days', Delivery::create(['tracking_number' => 'Also']));
        aged('open', '40 days', $gone);
        aged('open', '1 day');
        $tombstoned = Delivery::create(['tracking_number' => 'Tombstoned']);
        aged('view', '40 days', $tombstoned);
        $tombstoned->forceDelete();

        $before = [Activity::query()->withTrashed()->count(), Snapshot::query()->count(), FeedTombstone::query()->count()];

        $result = (new PruneActivities)(pretend: true);

        expect($result['verbs'])->toBe(['open' => 1, 'view' => 3])
            ->and($result['pruned'])->toBe(4)
            // Gone, Also and the tombstone's; Sally is still named by `open`.
            ->and($result['snapshots'])->toBe(3)
            ->and($result['tombstones'])->toBe(1)
            ->and([Activity::query()->withTrashed()->count(), Snapshot::query()->count(), FeedTombstone::query()->count()])->toBe($before);

        $this->artisan('storyfeed:prune', ['--pretend' => true])
            ->expectsTable(['Verb', 'Activities'], [['open', '1'], ['view', '3']])
            ->expectsOutputToContain('Would prune 4 activities, 3 snapshots and 1 tombstones. Nothing was deleted.')
            ->assertSuccessful();

        $real = (new PruneActivities)();

        expect([$real['pruned'], $real['snapshots'], $real['tombstones']])->toBe([4, 3, 1]);
    });

    it('says pruning is disabled when no window exists anywhere', function () {
        Story::verb('sign')->keepForever();

        $this->artisan('storyfeed:prune', ['--pretend' => true])
            ->expectsOutputToContain('Pruning is disabled')
            ->assertSuccessful();
    });
});

describe('the doctor', function () {
    it('warns when a window would remove rows on the next run', function () {
        Story::verb('view')->keepFor('30 days');

        aged('view', '40 days');
        aged('view', '40 days');
        aged('view', '10 days');

        $finding = Storyfeed::doctor(['retention'])->withCode('retention.backlog')->sole();

        expect($finding->severity)->toBe(Severity::Warning)
            ->and($finding->subject)->toBe(['verb' => 'view', 'count' => '2'])
            ->and($finding->message)->toContain('2 `view` activities are more than a day past its window (30 days)')
            ->and($finding->message)->toContain('storyfeed:prune --pretend');
    });

    it('allows a day of slack, so a feed pruned daily stays quiet', function () {
        Story::verb('view')->keepFor('30 days');

        aged('view', CarbonInterval::days(30)->addHours(12));

        expect(Storyfeed::doctor(['retention'])->has('retention.backlog'))->toBeFalse();
    });

    it('names a high-volume verb no window reaches, but not one kept forever', function () {
        Story::verb('sign')->keepForever();
        $rows = [];

        foreach (['view', 'sign'] as $verb) {
            foreach (range(1, Retention::HIGH_VOLUME) as $i) {
                $rows[] = ['uid' => (string) str()->ulid(), 'verb' => $verb, 'published_at' => now()->subDay()->format('Y-m-d H:i:s.u')];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table((new Activity)->getTable())->insert($chunk);
        }

        aged('ping', '1 day');

        $report = Storyfeed::doctor(['retention']);
        $finding = $report->withCode('retention.unbounded')->sole();

        expect($finding->severity)->toBe(Severity::Info)
            ->and($finding->subject)->toBe(['verb' => 'view', 'count' => (string) Retention::HIGH_VOLUME])
            ->and($finding->message)->toContain("Story::verb('view')->keepFor('90 days')");

        config()->set('storyfeed.prune.after_days', 365);

        expect(Storyfeed::doctor(['retention'])->has('retention.unbounded'))->toBeFalse();
    });
});
