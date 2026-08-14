<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function participantRows(Activity $activity): array
{
    return DB::table(SyncParticipants::table())
        ->where('activity_id', $activity->getKey())
        ->orderBy('role')
        ->pluck('role')
        ->all();
}

it('finds an activity by every role it fills', function () {
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $file = Delivery::create(['tracking_number' => 'annual-report.fig']);
    $client = Customer::create(['name' => 'Acme']);
    $workspace = Customer::create(['name' => 'Workspace']);

    $activity = Storyfeed::activity()
        ->actor($user)
        ->verb('upload', $file)
        ->to($client)
        ->context($workspace)
        ->publish();

    expect(participantRows($activity))->toBe(['actor', 'context', 'object', 'target']);

    foreach ([$user, $file, $client, $workspace] as $entity) {
        expect(Storyfeed::feed()->involving($entity)->get()->items())
            ->toHaveCount(1, get_class($entity).' should be found by involving()');
    }
});

it('finds an activity whose only mention of the entity is the context', function () {
    // The Newsroom's CreateTask/ReviseDocument shape: context set, no target.
    $user = User::create(['name' => 'Deja', 'email' => 'deja@example.com']);
    $task = Delivery::create(['tracking_number' => 'T-1']);
    $project = Customer::create(['name' => 'Password Crackdown']);

    Storyfeed::activity()->actor($user)->verb('create', $task)->context($project)->publish();

    expect(Storyfeed::feed()->involving($project)->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->context($project)->get()->items())->toHaveCount(1);
});

it('finds an entity own creation, which context() cannot', function () {
    // The gap this API closes: the Newsroom's CreateProject records the project
    // as the OBJECT with no context, so a context-scoped project page omits
    // the project's own creation.
    $user = User::create(['name' => 'Marcus', 'email' => 'marcus@example.com']);
    $client = Customer::create(['name' => 'Chirp']);
    $project = Customer::create(['name' => 'Bird Removal']);

    Storyfeed::activity()->actor($user)->verb('create', $project)->to($client)->publish();

    expect(Storyfeed::feed()->involving($project)->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->context($project)->get()->items())->toBeEmpty();
});

it('does not match an entity that shares a morph alias', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $one = Delivery::create(['tracking_number' => 'A']);
    $other = Delivery::create(['tracking_number' => 'B']);

    Storyfeed::activity()->actor($user)->verb('confirm', $one)->publish();

    expect(Storyfeed::feed()->involving($other)->get()->items())->toBeEmpty();
});

it('finds activities involving a party', function () {
    $invoice = Delivery::create(['tracking_number' => 'INV-9']);
    $party = Storyfeed::party('Stripe');

    Storyfeed::activity()->actor($party)->verb('sync', $invoice)->publish();

    expect(Storyfeed::feed()->involving($party)->get()->items())->toHaveCount(1);
});

it('keeps group counts scope-correct when involving narrows a group', function () {
    Storyfeed::grammar(['*.upload' => ':actor uploaded :object']);
    Storyfeed::aggregateGrammar(['repeat.upload' => ':actor uploaded :count files']);

    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $project = Customer::create(['name' => 'Concur']);

    // Four uploads by one actor: two inside the project, two outside it.
    foreach (range(1, 2) as $i) {
        Storyfeed::activity()->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "in-{$i}"]))
            ->context($project)->publish();
    }

    foreach (range(1, 2) as $i) {
        Storyfeed::activity()->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "out-{$i}"]))
            ->publish();
    }

    $all = Storyfeed::feed()->get()->toArray()['items'];
    $scoped = Storyfeed::feed()->involving($project)->get()->toArray()['items'];

    expect($all[0]['count'])->toBe(4)
        ->and($scoped)->toHaveCount(1)
        ->and($scoped[0]['kind'])->toBe('group')
        ->and($scoped[0]['count'])->toBe(2); // recounted within scope, not 4
});

it('re-syncs when replace() supersedes an earlier activity', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
    $doc = Delivery::create(['tracking_number' => 'D-1']);
    $first = Customer::create(['name' => 'First']);
    $second = Customer::create(['name' => 'Second']);

    Storyfeed::activity()->actor($user)->verb('update', $doc)->to($first)->replace()->publish();
    Storyfeed::activity()->actor($user)->verb('update', $doc)->to($second)->replace()->publish();

    // The superseded row is gone, and so are its participant rows.
    expect(Storyfeed::feed()->involving($first)->get()->items())->toBeEmpty()
        ->and(Storyfeed::feed()->involving($second)->get()->items())->toHaveCount(1)
        ->and(DB::table(SyncParticipants::table())->count())->toBe(3); // actor, object, target
});

it('indexes composite parents and their members', function () {
    $user = User::create(['name' => 'Tomás', 'email' => 'tomas@example.com']);
    $project = Customer::create(['name' => 'Spring Campaign']);
    $files = collect(range(1, 3))->map(fn ($i) => Delivery::create(['tracking_number' => "f{$i}"]));

    Storyfeed::activity()->actor($user)->verb('upload')->objects($files)->to($project)->publish();

    // log() is the atomic timeline: the three members appear, the object-less
    // parent does not (it would double the timeline). All four rows carry the
    // target, so all four are indexed — the read mode decides what surfaces.
    expect(Storyfeed::feed()->log()->involving($files->first())->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->log()->involving($project)->get()->items())->toHaveCount(3)
        ->and(DB::table(SyncParticipants::table())->where('role', 'target')->count())->toBe(4);

    // In an aggregated read the parent is the story: one composite node.
    $summary = Storyfeed::feed()->involving($project)->get()->toArray()['items'];
    expect($summary)->toHaveCount(1)->and($summary[0]['axis'])->toBe('composite');
});

it('backfills to exactly what publish-time sync would have written', function () {
    $user = User::create(['name' => 'Aiko', 'email' => 'aiko@example.com']);
    $file = Delivery::create(['tracking_number' => 'X']);
    $project = Customer::create(['name' => 'Port Migration']);

    Storyfeed::activity()->actor($user)->verb('upload', $file)->to($project)->publish();

    $expected = DB::table(SyncParticipants::table())
        ->orderBy('id')->get(['activity_id', 'role', 'entity_type', 'entity_id'])->toArray();

    DB::table(SyncParticipants::table())->delete();
    expect(Storyfeed::feed()->involving($project)->get()->items())->toBeEmpty();

    $this->artisan('storyfeed:participants')->assertSuccessful();

    $rebuilt = DB::table(SyncParticipants::table())
        ->orderBy('id')->get(['activity_id', 'role', 'entity_type', 'entity_id'])->toArray();

    expect($rebuilt)->toEqual($expected)
        ->and(Storyfeed::feed()->involving($project)->get()->items())->toHaveCount(1);

    // Idempotent: a second pass changes nothing.
    $this->artisan('storyfeed:participants')->assertSuccessful();
    expect(DB::table(SyncParticipants::table())->count())->toBe(count($expected));
});

it('reports unindexed activities through doctor, then goes quiet', function () {
    $user = User::create(['name' => 'Priya', 'email' => 'priya@example.com']);
    Storyfeed::activity()->actor($user)->verb('ping')->publish();

    expect(Storyfeed::doctor(['participants'])->problems())->toBeEmpty();

    DB::table(SyncParticipants::table())->delete();

    $report = Storyfeed::doctor(['participants']);

    expect($report->has('participants.unindexed'))->toBeTrue()
        ->and($report->withCode('participants.unindexed')->first()->message)
        ->toContain('storyfeed:participants');

    $this->artisan('storyfeed:participants')->assertSuccessful();

    expect(Storyfeed::doctor(['participants'])->problems())->toBeEmpty();
});

it('cascades participant rows when an activity is pruned', function () {
    config()->set('storyfeed.prune.after_days', 30);

    $user = User::create(['name' => 'Old', 'email' => 'old@example.com']);
    $file = Delivery::create(['tracking_number' => 'ancient']);

    Storyfeed::activity()->actor($user)->verb('upload', $file)
        ->publishedAt(now()->subDays(90))->publish();

    expect(DB::table(SyncParticipants::table())->count())->toBe(2);

    $this->artisan('storyfeed:prune')->assertSuccessful();

    expect(DB::table(SyncParticipants::table())->count())->toBe(0);
});

it('throws when the renamed for() is called on the read side', function () {
    Storyfeed::feed()->for(Customer::create(['name' => 'Nope']));
})->throws(InvalidArgumentException::class, 'renamed to involving()');
