<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;

function reflexiveActivity(string $verb = 'joined', array $roles = []): void
{
    $activity = Storyfeed::activity($verb)->publish();
    DB::table('feed_activities')->where('id', $activity->id)->update(array_replace([
        'actor_type' => 'unresolvable-member', 'actor_id' => '42',
        'object_type' => 'unresolvable-member', 'object_id' => '42',
    ], $roles));
}

it('reports recorded morph identity by verb and count as a note without resolving models', function () {
    reflexiveActivity();
    reflexiveActivity();
    reflexiveActivity();
    reflexiveActivity('updated');

    $report = Storyfeed::doctor(['reflexive']);
    $findings = $report->withCode('reflexive.actor_object');

    expect($findings)->toHaveCount(2)
        ->and($findings->first()->severity)->toBe(Severity::Info)
        ->and($findings->first()->subject)->toBe(['verb' => 'joined', 'count' => 3])
        ->and($findings->first()->message)->toContain('3 activities of verb `joined` record the same entity as actor and object')
        ->and($findings->last()->message)->toContain('1 activity of verb `updated` records')
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->fixes())->toBeEmpty();
});

it('requires both morph columns to match', function (array $roles) {
    reflexiveActivity(roles: $roles);
    expect(Storyfeed::doctor(['reflexive'])->all())->toBeEmpty();
})->with([
    'different id' => [['object_id' => '43']],
    'different type' => [['object_type' => 'another-member']],
    'null ids' => [['actor_id' => null, 'object_id' => null]],
    'null types' => [['actor_type' => null, 'object_type' => null]],
]);

it('does not report other equal role pairs', function (string $role) {
    reflexiveActivity(roles: [
        'object_id' => '43',
        'target_type' => 'unresolvable-member', 'target_id' => $role === 'object' ? '43' : '42',
        'context_type' => 'unresolvable-member', 'context_id' => '42',
    ]);
    expect(Storyfeed::doctor(['reflexive'])->all())->toBeEmpty();
})->with(['actor', 'object']);

it('is silent on empty history and absent activities tables', function () {
    expect(Storyfeed::doctor(['reflexive'])->all())->toBeEmpty();
    Schema::drop('feed_activities');
    expect(Storyfeed::doctor(['reflexive'])->all())->toBeEmpty();
});

it('ignores soft deleted history like actorless coverage', function () {
    reflexiveActivity();
    DB::table('feed_activities')->update(['deleted_at' => now()]);
    expect(Storyfeed::doctor(['reflexive'])->all())->toBeEmpty();
});

it('never fails the warning build gate for reflexive records', function () {
    reflexiveActivity();
    $this->artisan('storyfeed:doctor', ['--only' => ['reflexive'], '--fail-on' => 'warning'])
        ->expectsOutputToContain('same entity as actor and object')
        ->assertSuccessful();
});
