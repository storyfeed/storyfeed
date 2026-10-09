<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\BackfillAncestors;
use Storyfeed\Actions\RebuildAncestors;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;
use Storyfeed\Models\Snapshot;
use Storyfeed\Tests\Fixtures\Models\BackfillContainer;

beforeEach(function () {
    Relation::morphMap(['container' => BackfillContainer::class]);
    BackfillContainer::install();
    BackfillContainer::$declaresParent = false;
});

afterEach(function () {
    BackfillContainer::$declaresParent = false;
});

function backfillContainer(string $name, ?Model $parent = null): BackfillContainer
{
    return BackfillContainer::create(['name' => $name, 'parent_type' => $parent?->getMorphClass(), 'parent_id' => $parent?->getKey()]);
}

function backfillRows($activity): array
{
    return DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->orderBy('id')->get()->keyBy('id')->toArray();
}

it('distinguishes explicit null parent declarations in both entity forms', function () {
    expect(FeedEntity::make()->parentDeclared)->toBeFalse()
        ->and((new FeedEntity)->parentDeclared)->toBeFalse()
        ->and(FeedEntity::make(parent: null)->parentDeclared)->toBeTrue()
        ->and((new FeedEntity(parent: null))->parentDeclared)->toBeTrue()
        ->and(FeedEntity::make()->parent(null)->parentDeclared)->toBeTrue();
    $root = backfillContainer('Root');
    expect((new SnapshotEntity)($root)->meta)->not->toHaveKey('parent');
    BackfillContainer::$declaresParent = true;
    expect((new SnapshotEntity)($root)->meta)->toHaveKey('parent', null);
});

it('fills distant history after a model first declares parent for each walking role', function (string $role) {
    $root = backfillContainer('Root');
    $one = backfillContainer('One', $root);
    $two = backfillContainer('Two', $one);
    $child = backfillContainer('Child', $two);
    $builder = Storyfeed::activity()->anonymously()->action('create');
    $activity = match ($role) {
        'object' => $builder->object($child)->publish(),
        'target' => $builder->to($child)->publish(),
        'context' => $builder->context($child)->publish(),
    };
    $before = backfillRows($activity);
    expect(array_column($before, 'role'))->not->toContain('ancestor');
    BackfillContainer::$declaresParent = true;
    $this->artisan('storyfeed:participants --ancestors --missing --writers-paused --chunk=1')->assertSuccessful();
    expect(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('role', 'ancestor')->orderBy('distance')->pluck('distance', 'entity_id')->all())
        ->toBe([$two->id => 1, $one->id => 2, $root->id => 3])
        ->and(array_intersect_key(backfillRows($activity), $before))->toEqual($before)
        ->and(Storyfeed::feed()->involving($root)->get()->items())->toHaveCount(1);
    $filled = backfillRows($activity);
    $new = backfillContainer('New root');
    DB::table('nested_containers')->where('id', $child->id)->update(['parent_id' => $new->id]);
    $this->artisan('storyfeed:participants --ancestors --missing --writers-paused')->assertSuccessful();
    expect(backfillRows($activity))->toEqual($filled);
})->with(['object', 'target', 'context']);

it('decides gaps per role while preserving moved recorded paths and recorded none', function () {
    BackfillContainer::$declaresParent = true;
    $old = backfillContainer('Old');
    $new = backfillContainer('New');
    $object = backfillContainer('Object', $old);
    $context = backfillContainer('Recorded none');
    BackfillContainer::$declaresParent = false;
    $target = backfillContainer('Gap', $new);
    // Publish with a mixed snapshot history, as an upgrade into parent() sees.
    $activity = Storyfeed::activity()->anonymously()->action('create', $object)->to($target)->context($context)->publish();
    Snapshot::whereKey($activity->cached_object_id)->update(['meta' => ['parent' => ['type' => 'container', 'id' => $old->id]]]);
    Snapshot::whereKey($activity->cached_context_id)->update(['meta' => ['parent' => null]]);
    DB::table(SyncParticipants::table())->insert([
        'activity_id' => $activity->id, 'entity_type' => 'container', 'entity_id' => (string) $old->id,
        'role' => 'ancestor', 'distance' => 3, 'published_at' => $activity->published_at,
    ]);
    $before = backfillRows($activity);
    DB::table('nested_containers')->whereIn('id', [$object->id, $context->id])->update(['parent_type' => 'container', 'parent_id' => $new->id]);
    BackfillContainer::$declaresParent = true;
    expect((new BackfillAncestors)->missingRoles($activity))->toBe(['target']);
    $this->artisan('storyfeed:participants --ancestors --missing --writers-paused')->assertSuccessful();
    expect(array_intersect_key(backfillRows($activity), $before))->toEqual($before)
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('role', 'ancestor')->pluck('distance', 'entity_id')->all())
        ->toBe([$old->id => 3, $new->id => 1]);
});

it('remembers an empty gap walk without changing shared snapshots or consuming other activities gaps', function () {
    $root = backfillContainer('No parent yet');
    $one = Storyfeed::activity()->anonymously()->action('create', $root)->publish();
    $two = Storyfeed::activity()->anonymously()->action('create', $root)->publish();
    $two->update(['cached_object_id' => null]);
    $snapshotBefore = Snapshot::find($one->cached_object_id)->getAttributes();
    BackfillContainer::$declaresParent = true;
    (new BackfillAncestors)($one);
    expect((new BackfillAncestors)->missingRoles($two))->toBe(['object']);
    (new BackfillAncestors)($two);
    $parent = backfillContainer('Later parent');
    DB::table('nested_containers')->where('id', $root->id)->update(['parent_type' => 'container', 'parent_id' => $parent->id]);
    $this->artisan('storyfeed:participants --ancestors --missing --writers-paused')->assertSuccessful();
    expect(backfillRows($one))->toHaveCount(1)->and(backfillRows($two))->toHaveCount(1)
        ->and(Snapshot::find($one->cached_object_id)->getAttributes())->toBe($snapshotBefore);
});

it('merges gap paths at the shortest distance without updating any existing identity', function () {
    $root = backfillContainer('Root');
    $one = backfillContainer('One', $root);
    $two = backfillContainer('Two', $one);
    $object = backfillContainer('Object', $two);
    $target = backfillContainer('Target', $one);
    $activity = Storyfeed::activity()->anonymously()->action('create', $object)->to($target)->context($two)->publish();
    DB::table(SyncParticipants::table())->insert([
        'activity_id' => $activity->id, 'entity_type' => 'container', 'entity_id' => (string) $root->id,
        'role' => 'ancestor', 'distance' => 5,
    ]);
    $before = backfillRows($activity);
    BackfillContainer::$declaresParent = true;
    (new BackfillAncestors)($activity);
    expect(array_intersect_key(backfillRows($activity), $before))->toEqual($before)
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('role', 'ancestor')->pluck('distance', 'entity_id')->all())
        ->toBe([$root->id => 5, $one->id => 1]);
});

it('warns about declared parent history gaps and clears the warning after backfill', function () {
    $root = backfillContainer('Root');
    $child = backfillContainer('Child', $root);
    Storyfeed::activity()->anonymously()->action('create', $child)->publish();
    expect(Storyfeed::doctor(['ancestors'])->has('ancestors.missing'))->toBeFalse();
    BackfillContainer::$declaresParent = true;
    $finding = collect(Storyfeed::doctor(['ancestors'])->findings)->firstWhere('code', 'ancestors.missing');
    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->message)->toContain('storyfeed:participants --ancestors --missing --writers-paused');
    $this->artisan('storyfeed:participants --ancestors --missing --writers-paused')->assertSuccessful();
    expect(Storyfeed::doctor(['ancestors'])->has('ancestors.missing'))->toBeFalse();
});

it('resumes gaps only after committed chunks and keeps rewrite progress separate', function () {
    $root = backfillContainer('Root');
    $child = backfillContainer('Child', $root);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->anonymously()->action('create', $child)->publish();
    }
    BackfillContainer::$declaresParent = true;
    expect(fn () => (new RebuildAncestors)(missing: true, batchSize: 1, progress: fn () => throw new RuntimeException('interrupted')))->toThrow(RuntimeException::class, 'interrupted');
    expect(json_decode(Meta::where('key', RebuildAncestors::MISSING_STATE)->value('value'), true)['done'])->toBe(1)
        ->and(Meta::where('key', RebuildAncestors::STATE)->exists())->toBeFalse();
    expect(fn () => (new RebuildAncestors)(missing: true))->toThrow(RuntimeException::class, 'interrupted');
    expect((new RebuildAncestors)(missing: true, resume: true, batchSize: 1))->toBe(['processed' => 3])
        ->and(Meta::where('key', RebuildAncestors::MISSING_STATE)->exists())->toBeFalse()
        ->and(DB::table(SyncParticipants::table())->where('role', 'ancestor')->count())->toBe(3);
    expect(fn () => (new RebuildAncestors)(missing: true, resume: true))->toThrow(RuntimeException::class, 'no interrupted');
});

it('refuses live writer mode and stops when a publisher commits between backfill chunks', function () {
    $root = backfillContainer('Root');
    $child = backfillContainer('Child', $root);
    Storyfeed::activity()->anonymously()->action('create', $child)->publish();
    Storyfeed::activity()->anonymously()->action('revise', $child)->publish();
    $this->artisan('storyfeed:participants --ancestors --missing')->assertFailed();
    BackfillContainer::$declaresParent = true;
    expect(fn () => (new RebuildAncestors)(missing: true, batchSize: 1, progress: function () use ($child) {
        Storyfeed::activity()->anonymously()->action('close', $child)->publish();
    }))->toThrow(RuntimeException::class, 'history changed');
    expect(json_decode(Meta::where('key', RebuildAncestors::MISSING_STATE)->value('value'), true)['done'])->toBe(1);
    expect((new RebuildAncestors)(missing: true, restart: true))->toBe(['processed' => 3]);
});

it('detects a separate publisher committing during a gaps-only backfill', function () {
    if (DB::getDriverName() !== 'sqlite' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Two-process SQLite probe requires pcntl.');
    }
    $root = backfillContainer('Root');
    $child = backfillContainer('Child', $root);
    Storyfeed::activity()->anonymously()->action('create', $child)->publish();
    $second = Storyfeed::activity()->anonymously()->action('revise', $child)->publish();
    $database = tempnam(sys_get_temp_dir(), 'storyfeed-ancestor-race-');
    $previous = config('database.connections.testing');
    DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
    config()->set('database.connections.testing.database', $database);
    DB::purge('testing');
    BackfillContainer::$declaresParent = true;
    try {
        expect(fn () => (new RebuildAncestors)(missing: true, batchSize: 1, progress: function () use ($child, $database) {
            // Run the writer in its own process and connection while the
            // backfill retains its maintenance lock between chunk commits.
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge('testing');
                    DB::statement('PRAGMA busy_timeout = 1000');
                    Storyfeed::activity()->anonymously()->action('close', $child)->publish();
                    DB::disconnect('testing');
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($database.'.error', $error->getMessage());
                    exit(1);
                }
            }
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }))->toThrow(RuntimeException::class, 'history changed');
        expect(json_decode(Meta::where('key', RebuildAncestors::MISSING_STATE)->value('value'), true)['done'])->toBe(1)
            ->and(Activity::count())->toBe(3);
        // The live publish refreshed a SHARED snapshot. The second historical
        // activity now appears recorded despite not having been backfilled:
        // additive inserts cannot make live writers safe for this format.
        expect(Snapshot::find($second->cached_object_id)->meta)->toHaveKey('parent')
            ->and(DB::table(SyncParticipants::table())->where('activity_id', $second->id)->where('role', 'ancestor')->exists())->toBeFalse();
    } finally {
        DB::purge('testing');
        config()->set('database.connections.testing', $previous);
        foreach (glob($database.'*') as $path) {
            unlink($path);
        }
    }
});
