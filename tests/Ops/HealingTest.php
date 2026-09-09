<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Contracts\FeedHealer;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Healing\HealFeed;
use Storyfeed\Healing\HealOutcome;
use Storyfeed\Healing\StoryRetirement;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;
use Storyfeed\Support\SyncToken;

beforeEach(function () {
    // A synthetic object backed by hard-deleting source rows, with no model hooks.
    Schema::create('healing_sources', function ($table) {
        $table->id();
        $table->boolean('detached')->default(false);
    });
});

function healingStory(int $sourceId = 1): Activity
{
    $story = Storyfeed::activity()->verb('asset.published')->anonymously()->publishedAt(now()->subDays(60));
    $story->activity->object_type = 'external_asset';
    $story->activity->object_id = $sourceId;

    return $story->publish();
}

function healingRetirement(Activity $activity): StoryRetirement
{
    return new StoryRetirement(
        label: 'Absent asset',
        activityId: $activity->id,
        whenAbsent: fn (Activity $live): bool => $live->verb === 'asset.published'
            && $live->object_type === 'external_asset'
            && ! DB::table('healing_sources')->where('id', $live->object_id)->exists(),
        meta: ['reason' => 'source permanently absent'],
    );
}

function registerRetirements(array $candidates, string $key = 'assets'): FeedHealer
{
    $healer = new class($candidates, $key) implements FeedHealer
    {
        public function __construct(private array $items, private string $name) {}

        public function key(): string
        {
            return $this->name;
        }

        public function candidates(): iterable
        {
            yield from $this->items;
        }
    };

    Storyfeed::healers([$healer]);

    return $healer;
}

function runRetirements(bool $dryRun = false, ?array $only = null): array
{
    return iterator_to_array(app(HealFeed::class)->run($dryRun, $only), false);
}

it('previews every candidate with no writes, events, or token changes', function () {
    $absent = healingStory();
    $present = healingStory(2);
    DB::table('healing_sources')->insert(['id' => 2]);
    registerRetirements([healingRetirement($absent), healingRetirement($present)]);
    $token = SyncToken::bump();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    Event::fake([ActivityDeleted::class]);

    $results = runRetirements(dryRun: true);

    expect(array_column($results, 'outcome'))->toBe([HealOutcome::Retired, HealOutcome::Unchanged])
        ->and($results[0]->candidate->meta)->toBe(['reason' => 'source permanently absent'])
        ->and($writes)->toBe([])
        ->and(Activity::count())->toBe(2)
        ->and(SyncToken::current())->toBe($token);
    Event::assertNotDispatched(ActivityDeleted::class);
});

it('retires a live story for permanent absence and converges without new storage', function () {
    $sourceId = DB::table('healing_sources')->insertGetId([]);
    $activity = healingStory($sourceId);
    DB::table('healing_sources')->where('id', $sourceId)->delete();
    registerRetirements([healingRetirement($activity)]);
    $token = SyncToken::bump();

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Retired)
        ->and($activity->fresh()->trashed())->toBeTrue()
        ->and($activity->fresh()->published_at->equalTo($activity->published_at))->toBeTrue()
        ->and(DB::table('feed_participants')->where('activity_id', $activity->id)->count())->toBe(0)
        ->and(SyncToken::current())->not->toBe($token);

    $after = SyncToken::current();
    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and(SyncToken::current())->toBe($after)
        ->and(Meta::pluck('key')->all())->toBe(['sync_token'])
        ->and(Activity::withTrashed()->count())->toBe(1);
});

it('leaves a detached but present source story alone', function () {
    DB::table('healing_sources')->insert(['id' => 1, 'detached' => true]);
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and($activity->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->toBeNull();
});

it('rechecks absence at execution after a preview has reported retirement', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    expect(runRetirements(dryRun: true)[0]->outcome)->toBe(HealOutcome::Retired);

    // Even if an app's permanent-absence assumption was premature, do not
    // delete when the source is present by the time the request is applied.
    DB::table('healing_sources')->insert(['id' => 1]);

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and($activity->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->toBeNull();
});

it('passes the freshly locked row to policy instead of the enumerated row', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    DB::table('healing_sources')->insert(['id' => 2]);
    Activity::whereKey($activity->id)->update(['object_id' => 2]);

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and($activity->fresh()->trashed())->toBeFalse();
});

it('does not act on a removed story even if its source is absent', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    $activity->delete();

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and(Activity::count())->toBe(0)
        ->and(Activity::withTrashed()->count())->toBe(1)
        ->and(SyncToken::current())->toBeNull();
});

it('does not recreate a removed story after pruning erases its evidence', function () {
    DB::table('healing_sources')->insert(['id' => 1]);
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    $activity->delete();
    (new PruneActivities)(30);

    expect(Activity::withTrashed()->count())->toBe(0)
        ->and(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and(Activity::withTrashed()->count())->toBe(0)
        ->and(DB::table('healing_sources')->count())->toBe(1)
        ->and(SyncToken::current())->toBeNull();
});

it('never treats a missing activity as an instruction, even when explicitly named', function () {
    registerRetirements([new StoryRetirement('Missing', 987654, function () {
        throw new RuntimeException('There is no live row to evaluate.');
    })]);

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and(Activity::withTrashed()->count())->toBe(0)
        ->and(Meta::count())->toBe(0);
});

it('requests a row lock before evaluating policy inside the write transaction', function () {
    $activity = healingStory();
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $grammar = new class($connection) extends SQLiteGrammar
    {
        public bool $lockedRead = false;

        protected function compileLock(Builder $query, $value)
        {
            $this->lockedRead = $value === true;

            return parent::compileLock($query, $value);
        }
    };
    $connection->setQueryGrammar($grammar);
    registerRetirements([new StoryRetirement('Locked', $activity->id, function (Activity $live) use ($connection, $grammar) {
        expect($grammar->lockedRead)->toBeTrue()
            ->and($connection->transactionLevel())->toBeGreaterThan(0);

        return true;
    })]);

    try {
        expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Retired);
    } finally {
        $connection->setQueryGrammar($original);
    }
});

it('evaluates preview policy without a transaction or lock', function () {
    $activity = healingStory();
    registerRetirements([new StoryRetirement('Preview', $activity->id, function () {
        expect(DB::transactionLevel())->toBe(0);

        return true;
    })]);

    expect(runRetirements(dryRun: true)[0]->outcome)->toBe(HealOutcome::Retired);
});

it('rolls back retirement and participant cleanup if the resync write fails', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    Schema::drop('feed_meta');
    Event::fake([ActivityDeleted::class]);

    expect(fn () => runRetirements())->toThrow(QueryException::class)
        ->and($activity->fresh()->trashed())->toBeFalse()
        ->and(DB::table('feed_participants')->where('activity_id', $activity->id)->count())->toBe(1);
    Event::assertNotDispatched(ActivityDeleted::class);
});

it('delivers deletion after commit with the new resync token already visible', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    $observed = false;
    Event::listen(ActivityDeleted::class, function () use (&$observed, $activity) {
        expect(DB::transactionLevel())->toBe(0)
            ->and($activity->fresh()->trashed())->toBeTrue()
            ->and(SyncToken::current())->not->toBeNull();
        $observed = true;
    });

    runRetirements();
    expect($observed)->toBeTrue();
});

it('streams an earlier committed retirement with its token if a later policy fails', function () {
    $first = healingStory();
    $second = healingStory(2);
    registerRetirements([
        healingRetirement($first),
        new StoryRetirement('Fail', $second->id, fn () => throw new RuntimeException('Policy failed')),
    ]);
    $results = [];

    try {
        foreach (app(HealFeed::class)->run() as $result) {
            $results[] = $result;
        }
        test()->fail('Expected policy failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Policy failed');
    }

    expect($results)->toHaveCount(1)
        ->and($first->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->not->toBeNull();
});

it('selects registered healers and prints preview policy metadata', function () {
    $first = healingStory();
    $second = healingStory(2);
    registerRetirements([healingRetirement($first)], 'assets');
    registerRetirements([healingRetirement($second)], 'other');

    $this->artisan('storyfeed:heal', ['--dry-run' => true, '--only' => ['assets']])
        ->expectsOutputToContain('sync_token')
        ->expectsOutputToContain('source permanently absent')
        ->expectsOutputToContain('Would retire: 1; unchanged: 0.')
        ->assertSuccessful();
    expect(Activity::count())->toBe(2);

    $this->artisan('storyfeed:heal', ['--only' => ['assets']])
        ->expectsOutputToContain('Retired: 1; unchanged: 0.')
        ->assertSuccessful();
    expect($first->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeFalse();
});

it('rejects unknown selection before running any healer', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);

    expect(fn () => runRetirements(only: ['assets', 'typo']))->toThrow(InvalidArgumentException::class, 'Unknown healer [typo].')
        ->and($activity->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->toBeNull();
});

it('registers class healers through the container and can replace the registry', function () {
    $healer = registerRetirements([], 'first');
    app()->instance($healer::class, $healer);
    Storyfeed::healers([$healer::class], merge: false);
    expect(Storyfeed::registeredHealers())->toBe(['first' => $healer]);
    registerRetirements([], 'second');
    expect(array_keys(Storyfeed::registeredHealers()))->toBe(['first', 'second']);
    Storyfeed::healers([], merge: false);
    expect(runRetirements())->toBe([]);
});

it('rejects empty healer keys and unsupported candidate types', function () {
    expect(fn () => registerRetirements([], ''))->toThrow(InvalidArgumentException::class);
    registerRetirements([Storyfeed::activity()->verb('new')->anonymously()]);

    expect(fn () => runRetirements())->toThrow(TypeError::class)
        ->and(Activity::withTrashed()->count())->toBe(0);
});

it('leaves a vetoed deletion unchanged without bumping the token', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    Event::listen('eloquent.deleting: '.Activity::class, fn () => false);

    expect(runRetirements()[0]->outcome)->toBe(HealOutcome::Unchanged)
        ->and($activity->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->toBeNull();
});

it('preserves numeric healer names across registration and selection', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)], '123');
    registerRetirements([], 'other');

    $results = runRetirements(only: ['123']);
    expect($results)->toHaveCount(1)
        ->and($results[0]->healer)->toBe('123')
        ->and($results[0]->outcome)->toBe(HealOutcome::Retired);
});

it('rejects a separate activity connection before preview or writes can promise atomic retirement', function () {
    $activity = healingStory();
    registerRetirements([healingRetirement($activity)]);
    $model = new class extends Activity
    {
        protected $connection = 'healing_other';
    };
    config()->set('database.connections.healing_other', config('database.connections.testing'));
    config()->set('storyfeed.models.activity', $model::class);

    foreach ([true, false] as $dryRun) {
        expect(fn () => runRetirements($dryRun))->toThrow(InvalidArgumentException::class, 'default database connection');
    }
    expect($activity->fresh()->trashed())->toBeFalse()
        ->and(SyncToken::current())->toBeNull();
});
