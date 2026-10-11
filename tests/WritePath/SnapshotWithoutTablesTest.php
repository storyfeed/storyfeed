<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Tests\Fixtures\Models\RegisteredProject;
use Storyfeed\Tests\Fixtures\Models\StoragelessProject;

/*
 * A Feedable model's save writes nothing when core's tables are absent, even
 * without Storyfeed::withoutStorage(): a feed table must never fail a save in
 * an app's admin (#131, from teylabs.com's repro on v0.20.0).
 */
beforeEach(function () {
    config()->set('database.connections.bare', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.default', 'bare');

    Schema::create('storageless_projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Relation::morphMap([
        'storageless_project' => StoragelessProject::class,
        'registered_project' => RegisteredProject::class,
    ]);
});

it('saves a model using InteractsWithFeed', function () {
    expect(Storyfeed::isRecording())->toBeTrue();

    $project = StoragelessProject::create(['name' => 'Storyfeed']);
    $project->update(['name' => 'Storyfeed core']);

    expect($project->fresh()->name)->toBe('Storyfeed core')
        ->and(Schema::hasTable('feed_snapshots'))->toBeFalse();
});

it('saves a model registered with Storyfeed::feedable()', function () {
    Storyfeed::feedable(RegisteredProject::class)
        ->toFeedUsing(fn (RegisteredProject $project, FeedEntity $entity) => $entity->label($project->name));

    $project = RegisteredProject::create(['name' => 'TalkingFeed']);

    expect($project->exists)->toBeTrue();
});
