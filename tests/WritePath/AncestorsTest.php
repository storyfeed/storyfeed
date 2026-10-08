<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\RebuildAncestors;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Meta;
use Storyfeed\Models\Snapshot;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;
use Workbench\App\Models\Customer;

beforeEach(function () {
    Relation::morphMap(['container' => NestedContainer::class]);
    NestedContainer::install();
});

function nestedContainer(string $name, ?Model $parent = null): NestedContainer
{
    return NestedContainer::create(['name' => $name, 'parent_type' => $parent?->getMorphClass(), 'parent_id' => $parent?->getKey()]);
}

function nestedChain(int $links = 6): array
{
    $chain = [nestedContainer('Tenant')];
    foreach (range(1, $links) as $i) {
        $chain[] = nestedContainer("Level {$i}", end($chain));
    }

    return $chain;
}

function indexedAncestors($activity): array
{
    return DB::table(SyncParticipants::table())->where('activity_id', $activity->getKey())->where('role', 'ancestor')
        ->orderBy('distance')->pluck('distance', 'entity_id')->all();
}

it('snapshots a declared parent and accepts both entity forms', function () {
    $parent = nestedContainer('Tenant');
    $child = nestedContainer('Task', $parent);
    expect(FeedEntity::make(parent: $parent)->parent)->toBe($parent)
        ->and(FeedEntity::make()->parent($parent)->parent(null)->parent)->toBeNull()
        ->and(Snapshot::where('model_type', 'container')->where('model_id', $child->id)->first()->meta['parent'])
        ->toEqual(['type' => 'container', 'id' => $parent->id]);
});

it('walks six levels from the object with indexed involving and recorded depths', function () {
    $chain = nestedChain();
    $activity = Storyfeed::activity()->anonymously()->action('complete', end($chain))->publish();
    $expected = [];
    foreach (array_reverse(array_slice($chain, 0, -1)) as $i => $parent) {
        $expected[$parent->id] = $i + 1;
        expect(Storyfeed::feed()->involving($parent)->get()->items())->toHaveCount(1);
    }
    expect(indexedAncestors($activity))->toBe($expected);
});

it('merges chains from object target and context at the shortest distance', function () {
    [$tenant, $workspace, $project, $folder] = nestedChain(3);
    $activity = Storyfeed::activity()->anonymously()->action('revise', $folder)->to($workspace)->context($project)->publish();
    expect(indexedAncestors($activity))->toBe([$tenant->id => 1])
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->count())->toBe(4);
});

it('keeps minimum recorded distances when separate object and target chains converge', function () {
    $tenant = nestedContainer('Tenant');
    $workspace = nestedContainer('Workspace', $tenant);
    $project = nestedContainer('Project', $workspace);
    $object = nestedContainer('Deep task', $project);
    $target = nestedContainer('Shallow task', $workspace);
    $activity = Storyfeed::activity()->anonymously()->action('move', $object)->to($target)->publish();
    expect(indexedAncestors($activity))->toEqual([$project->id => 1, $workspace->id => 1, $tenant->id => 2])
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('role', '<>', 'ancestor')->pluck('distance')->all())->toBe([0, 0])
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->count())->toBe(5);
});

it('bounds an oversized configured cap to the stored distance range', function () {
    $chain = nestedChain(256);
    config()->set('storyfeed.ancestors.max_depth', 1000);
    $activity = Storyfeed::activity()->anonymously()->action('create', end($chain))->publish();
    expect(indexedAncestors($activity))->toHaveCount(255)
        ->and(max(indexedAncestors($activity)))->toBe(255)
        ->and(Storyfeed::feed()->involving($chain[0])->get()->items())->toBeEmpty()
        ->and(Storyfeed::feed()->involving(end($chain))->get()->items())->toHaveCount(1);
});

it('never walks Sally home tenant or any other non-place role', function () {
    $home = nestedContainer('Sally home tenant');
    $sally = nestedContainer('Sally', $home);
    $acme = nestedContainer('Acme');
    $task = nestedContainer('Acme task', $acme);
    Storyfeed::activity()->by($sally)->action('complete', $task)->origin($sally)->result($sally)->instrument($sally)->publish();
    expect(Storyfeed::feed()->involving($sally)->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->involving($acme)->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->involving($home)->get()->items())->toBeEmpty();
});

it('walks a self-acting container only when it is also the object', function () {
    $tenant = nestedContainer('Tenant');
    $project = nestedContainer('Project', $tenant);
    Storyfeed::activity()->by($project)->action('close')->publish();
    expect(Storyfeed::feed()->involving($tenant)->get()->items())->toBeEmpty()
        ->and(Storyfeed::doctor(['ancestors'])->has('ancestors.actor_only'))->toBeTrue();
    $activity = Storyfeed::activity()->by($project)->action('close', $project)->publish();
    expect(indexedAncestors($activity))->toBe([$tenant->id => 1])
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->count())->toBe(2)
        ->and(Storyfeed::feed()->involving($tenant)->get()->items())->toHaveCount(1);
});

it('keeps recorded paths after a move and rebuilds from current parents', function () {
    $old = nestedContainer('Old tenant');
    $new = nestedContainer('New tenant');
    $task = nestedContainer('Task', $old);
    $first = Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    $task->update(['parent_id' => $new->id]);
    $second = Storyfeed::activity()->anonymously()->action('revise', $task)->publish();
    expect(indexedAncestors($first))->toBe([$old->id => 1])->and(indexedAncestors($second))->toBe([$new->id => 1]);
    // An explicit rebuild must consult the model even if snapshot metadata is stale.
    DB::table('nested_containers')->where('id', $task->id)->update(['parent_id' => $old->id]);
    $this->artisan('storyfeed:participants --ancestors --writers-paused --chunk=1')->assertSuccessful();
    expect(indexedAncestors($first))->toBe([$old->id => 1])->and(indexedAncestors($second))->toBe([$old->id => 1]);
});

it('bounds cycles and caps without losing the activity', function () {
    [$tenant, $project, $task] = nestedChain(2);
    $tenant->update(['parent_type' => 'container', 'parent_id' => $task->id]);
    $activity = Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    expect(indexedAncestors($activity))->toBe([$project->id => 1, $tenant->id => 2]);
    config()->set('storyfeed.ancestors.max_depth', 1);
    (new SyncParticipants)($activity);
    expect(indexedAncestors($activity))->toBe([$project->id => 1]);
    config()->set('storyfeed.ancestors.max_depth', 0);
    (new SyncParticipants)($activity);
    expect(indexedAncestors($activity))->toBe([])->and(Storyfeed::feed()->involving($task)->get()->items())->toHaveCount(1);
});

it('stops at missing parents and diagnoses the broken chain', function () {
    [$tenant, $project, $task] = nestedChain(2);
    DB::table('nested_containers')->where('id', $tenant->id)->delete();
    $activity = Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    expect(indexedAncestors($activity))->toBe([$project->id => 1])
        ->and(Storyfeed::doctor(['ancestors'])->has('ancestors.unresolvable'))->toBeTrue()
        ->and(Storyfeed::feed()->involving($task)->get()->items())->toHaveCount(1);
});

it('fully rewrites changed roles and ancestors on edit', function () {
    [$tenant, $task] = nestedChain(1);
    $other = nestedContainer('Other');
    $activity = Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    $activity->object()->associate($other);
    $activity->cached_object_id = null;
    $activity->save();
    (new SyncParticipants)($activity);
    expect(indexedAncestors($activity))->toBe([])
        ->and(Storyfeed::feed()->involving($tenant)->get()->items())->toBeEmpty()
        ->and(Storyfeed::feed()->involving($other)->get()->items())->toHaveCount(1);
});

it('indexes composite member paths for both log and live reads', function () {
    $tenant = nestedContainer('Tenant');
    $one = nestedContainer('One', $tenant);
    $two = nestedContainer('Two', $tenant);
    Storyfeed::activity()->anonymously()->action('create')->objects([$one, $two])->publish();
    expect(Storyfeed::feed()->log()->involving($tenant)->get()->items())->toHaveCount(2)
        ->and(Storyfeed::feed()->involving($tenant)->get()->items())->toHaveCount(1);
});

it('resolves package party aliases with an enforced application map', function () {
    $party = Storyfeed::party('Workspace');
    $task = nestedContainer('Task', $party);
    $activity = Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    expect(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('role', 'ancestor')->value('entity_type'))
        ->toBe($party->getMorphClass())->and(Storyfeed::feed()->involving($party)->get()->items())->toHaveCount(1);
});

it('walks registered external models', function () {
    Storyfeed::feedable(ExternalContainer::class)->toFeedUsing(fn ($model) => FeedEntity::make($model->name)->parent(Customer::find($model->parent_id)));
    Relation::morphMap(['external.container' => ExternalContainer::class]);
    $parent = Customer::create(['name' => 'Tenant']);
    $child = ExternalContainer::create(['name' => 'External task', 'parent_id' => $parent->id]);
    $activity = Storyfeed::activity()->anonymously()->action('create', $child)->publish();
    expect(indexedAncestors($activity))->toBe([$parent->id => 1])
        ->and(Storyfeed::feed()->involving($parent)->get()->items())->toHaveCount(1);
});

it('resumes only after the last committed chunk and cleans its cursor', function () {
    [$tenant, $task] = nestedChain(1);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    }
    expect(fn () => (new RebuildAncestors)(batchSize: 1, progress: fn () => throw new RuntimeException('interrupted')))->toThrow(RuntimeException::class, 'interrupted');
    expect(json_decode(Meta::where('key', RebuildAncestors::STATE)->value('value'), true)['done'])->toBe(1);
    expect(fn () => (new RebuildAncestors)())->toThrow(RuntimeException::class, 'interrupted');
    expect((new RebuildAncestors)(resume: true, batchSize: 1))->toBe(['processed' => 3])
        ->and(Meta::where('key', RebuildAncestors::STATE)->exists())->toBeFalse();
    expect(fn () => (new RebuildAncestors)(resume: true))->toThrow(RuntimeException::class, 'no interrupted');
});

it('requires paused writers and rejects incompatible rebuild options', function () {
    $this->artisan('storyfeed:participants --ancestors')->assertFailed();
    $this->artisan('storyfeed:participants --resume')->assertFailed();
    $this->artisan('storyfeed:participants --ancestors --writers-paused --missing')->assertFailed();
    $this->artisan('storyfeed:participants --ancestors --writers-paused --resume --restart')->assertFailed();
});

class ExternalContainer extends Model
{
    protected $table = 'nested_containers';

    protected $guarded = [];
}

it('upgrades duplicate direct identities and retains the covering lookup plan', function () {
    $migration = include __DIR__.'/../../database/migrations/add_ancestors_to_feed_participants_table.php.stub';
    $migration->down();
    $rows = [];
    foreach (range(1, 2000) as $id) {
        $rows[] = ['activity_id' => $id, 'role' => 'object', 'entity_type' => 'container', 'entity_id' => (string) $id, 'published_at' => now()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table(SyncParticipants::table())->insert($chunk);
    }
    DB::table(SyncParticipants::table())->insert(['activity_id' => 1, 'role' => 'actor', 'entity_type' => 'container', 'entity_id' => '1', 'published_at' => now()]);
    $lookup = DB::table(SyncParticipants::table())->select('activity_id')->where('entity_type', 'container')->where('entity_id', '1');
    $explain = function () use ($lookup): string {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('vacuum analyze feed_participants');
        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement('analyze table feed_participants');
        }

        return json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$lookup->toSql(), $lookup->getBindings()), JSON_THROW_ON_ERROR);
    };
    $before = $explain();
    $migration->up();
    $after = $explain();
    expect($before)->toContain('feed_participants_entity_published_index')
        ->and($after)->toContain('feed_participants_entity_published_index')
        ->and(DB::table(SyncParticipants::table())->where('activity_id', 1)->count())->toBe(1)
        ->and(DB::table(SyncParticipants::table())->where('distance', 0)->count())->toBe(2000)
        ->and(collect(Schema::getIndexes(SyncParticipants::table()))->pluck('columns')->flatten()->all())->not->toContain('distance');
    if ($path = env('STORYFEED_EXPLAIN_REPORT')) {
        file_put_contents($path, json_encode(['sql' => $lookup->toSql(), 'bindings' => $lookup->getBindings(), 'before' => json_decode($before), 'after' => json_decode($after)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
});

it('preserves recorded ancestry through tombstones while direct roles merge', function () {
    $tenant = nestedContainer('Tenant');
    $task = nestedContainer('Task', $tenant);
    $activity = Storyfeed::activity()->by($task)->action('close', $task)->publish();
    $task->delete();
    expect(indexedAncestors($activity))->toBe([$tenant->id => 1])
        ->and(Storyfeed::feed()->involving($tenant)->get()->items())->toHaveCount(1);
});

it('restarts an interrupted rebuild and rejects changed history or policy on resume', function () {
    [$tenant, $task] = nestedChain(1);
    Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    expect(fn () => (new RebuildAncestors)(progress: fn () => throw new RuntimeException('interrupted')))->toThrow(RuntimeException::class);
    config()->set('storyfeed.ancestors.max_depth', 3);
    expect(fn () => (new RebuildAncestors)(resume: true))->toThrow(RuntimeException::class, 'configuration changed');
    expect((new RebuildAncestors)(restart: true))->toBe(['processed' => 1]);
    expect(fn () => (new RebuildAncestors)(progress: fn () => throw new RuntimeException('interrupted')))->toThrow(RuntimeException::class);
    Storyfeed::activity()->anonymously()->action('revise', $task)->publish();
    expect(fn () => (new RebuildAncestors)(resume: true))->toThrow(RuntimeException::class, 'history changed');
    expect((new RebuildAncestors)(restart: true))->toBe(['processed' => 2]);
});

it('walks intermediate containers whose snapshots predate parent declarations', function () {
    $chain = nestedChain();
    Snapshot::where('model_type', 'container')->get()->each(fn ($snapshot) => $snapshot->update(['meta' => []]));
    $activity = Storyfeed::activity()->anonymously()->action('complete', end($chain))->publish();
    expect(indexedAncestors($activity))->toHaveCount(6)
        ->and(Storyfeed::feed()->involving($chain[0])->get()->items())->toHaveCount(1);
});
