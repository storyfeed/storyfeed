<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\SourceItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * A role with no model behind it (#85): the write path takes the same
 * `['type', 'label', 'url']` entity the array source reads. An attendee
 * changes their vote; the vote is a row nobody shows on its own, so it is
 * recorded inline rather than made Feedable.
 */

beforeEach(function () {
    $this->attendee = User::create(['name' => 'Ana', 'email' => 'ana@example.com']);
    $this->poll = Customer::create(['name' => 'Favourite framework']);
});

it('records the entity inline: its type and id in the role, the rest with the activity', function () {
    $activity = Storyfeed::activity('change')
        ->actor($this->attendee)
        ->object(['type' => 'vote', 'label' => 'their vote', 'url' => '/polls/1#vote'])
        ->target($this->poll)
        ->publish()
        ->fresh();

    expect($activity->object_type)->toBe('vote')
        ->and($activity->object_id)->toBeNull()
        ->and($activity->cached_object_id)->toBeNull()
        ->and($activity->entities)->toEqual(['object' => ['type' => 'vote', 'id' => null, 'label' => 'their vote', 'url' => '/polls/1#vote']])
        ->and($activity->object)->toBeNull()
        ->and($activity->cachedObject->label)->toBe('their vote')
        ->and(DB::table('feed_snapshots')->where('model_type', 'vote')->exists())->toBeFalse();
});

it('renders like any entity in the payload and the Activity Streams document', function () {
    Storyfeed::activity('change')
        ->actor($this->attendee)
        ->object(['type' => 'vote', 'label' => 'their vote', 'url' => '/polls/1#vote', 'data' => ['option' => 'Laravel']])
        ->target($this->poll)
        ->publish();

    $object = Storyfeed::feed()->log()->get()->toArray()[0]['object'];

    expect($object)->toMatchArray(['type' => 'vote', 'label' => 'their vote'])
        ->and($object['link']['href'])->toBe('/polls/1#vote')
        ->and($object['data'])->toBe(['option' => 'Laravel']);

    // An extension type the vocabulary does not know reads as an Object.
    expect(serialize_one(Activity::sole())['object'])->toMatchArray(['name' => 'their vote']);
});

it('fills headline tokens, and files the story under its type', function () {
    Story::for('vote')->verb('change')->headline(':actor changed :object on :target');

    Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote'])->target($this->poll)->publish();

    $node = Storyfeed::feed()->log()->get()->toArray()[0];

    expect($node['headline_template'])->toBe(':actor changed :object on :target')
        ->and($node['object']['label'])->toBe('their vote')
        ->and(serialize_one(Activity::sole())['summary'])->toContain('changed their vote on');
});

it('reads as the array source reads the same entity', function () {
    $entity = ['type' => 'vote', 'label' => 'their vote', 'url' => '/polls/1#vote', 'id' => 'vote-7'];

    $stored = Storyfeed::activity('change')->actor($this->attendee)->object($entity)->target($this->poll)->publish();

    $sourced = Storyfeed::feed()->log()->source(new ArraySource([
        SourceItem::make('change', $stored->published_at, actor: $this->attendee, object: $entity, target: $this->poll, id: $stored->uid),
    ]))->get()->toArray()[0];

    $read = Storyfeed::feed()->log()->get()->toArray()[0];

    expect($read['object'])->toEqual($sourced['object'])
        ->and($read['headline'] ?? null)->toEqual($sourced['headline'] ?? null);
});

it('is found by involving() only when it carries an id', function () {
    $without = Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote'])->publish();
    $with = Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote', 'id' => 'vote-7'])->publish();

    $rows = DB::table(SyncParticipants::table())->where('entity_type', 'vote')->get();

    expect($rows->pluck('activity_id')->all())->toBe([$with->getKey()])
        ->and($rows->sole()->entity_id)->toBe('vote-7')
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $without->getKey())->pluck('entity_type')->all())->toBe(['user']);
});

it('is never snapshotted, counted as unresolved or pruned by the trickle', function () {
    $activity = Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote', 'id' => 'vote-7'])->publish();

    expect(Activity::query()->uncached()->count())->toBe(0)
        ->and((new TrickleSnapshots)(prune: true))->toMatchArray(['pruned' => 0, 'unresolved' => 0])
        ->and(Activity::query()->whereKey($activity->getKey())->exists())->toBeTrue();
});

it('is not an unresolvable alias or an unindexed row to the doctor', function () {
    Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote'])->publish();
    Storyfeed::activity('find')->actor(['type' => 'attendee', 'label' => 'Someone in row 3'])->object(['type' => 'egg', 'label' => 'the hidden egg', 'id' => 'slide-12'])->publish();

    $report = Storyfeed::doctor(['entities', 'participants']);

    expect($report->has('entities.unresolvable'))->toBeFalse()
        ->and($report->has('participants.unindexed'))->toBeFalse();
});

it('takes an entity in every role, through record() and verb enums alike', function () {
    $activity = Storyfeed::record('find',
        actor: ['type' => 'attendee', 'label' => 'Someone in row 3'],
        object: ['type' => 'egg', 'label' => 'the hidden egg'],
        target: $this->poll,
        result: ['type' => 'prize', 'label' => 'a sticker'],
    )->fresh();

    expect($activity->actor_type)->toBe('attendee')
        ->and($activity->cachedActor->label)->toBe('Someone in row 3')
        ->and($activity->cachedResult->label)->toBe('a sticker')
        ->and(collect($activity->entities)->keys()->sort()->values()->all())->toBe(['actor', 'object', 'result']);
});

it('is replaced by a model or a party, and an anonymous actor clears it', function () {
    $pending = Storyfeed::activity('change')
        ->actor(['type' => 'attendee', 'label' => 'Someone'])
        ->object(['type' => 'vote', 'label' => 'their vote'])
        ->object($this->poll)
        ->anonymously();

    $activity = $pending->publish()->fresh();

    expect($activity->entities)->toBeNull()
        ->and($activity->object_type)->toBe($this->poll->getMorphClass())
        ->and($activity->actor_type)->toBeNull();
});

it('refuses an entity it cannot store, naming the role', function (array $entity, string $message) {
    expect(fn () => Storyfeed::activity('change')->object($entity))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no label' => [['type' => 'vote'], 'The [object] entity needs a [label].'],
    'no type' => [['label' => 'their vote'], 'The [object] entity needs a [type].'],
    'unknown key' => [['type' => 'vote', 'label' => 'their vote', 'name' => 'x'], 'Unknown key [name] on the [object] entity.'],
    'url not a string' => [['type' => 'vote', 'label' => 'their vote', 'url' => 7], 'must be a string'],
    'id too long' => [['type' => 'vote', 'label' => 'their vote', 'id' => str_repeat('x', 37)], 'up to 36 printable ASCII characters'],
]);

it('migrates idempotently in both directions', function () {
    $migration = include __DIR__.'/../../database/migrations/add_entities_to_feed_activities_table.php.stub';

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('feed_activities', 'entities'))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('feed_activities', 'entities'))->toBeFalse();

    $migration->up();
});

it('carries an entity through the queue', function () {
    $pending = Storyfeed::activity('change')->actor($this->attendee)->object(['type' => 'vote', 'label' => 'their vote', 'id' => 'vote-7']);

    $activity = unserialize(serialize($pending))->publish()->fresh();

    expect($activity->object_id)->toBe('vote-7')
        ->and($activity->cachedObject->label)->toBe('their vote');
});
