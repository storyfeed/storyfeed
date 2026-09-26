<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Exceptions\FeedableMorphMapViolation;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Storyfeed\Tests\Fixtures\Unaliased\Stray;
use Workbench\App\Models\Customer;

beforeEach(function () {
    Relation::requireMorphMap(false);
    config()->set('storyfeed.discovery.paths', [dirname(__DIR__).'/Fixtures/Unaliased']);
});

afterEach(function () {
    app(StoryManifest::class)->delete();
    Relation::requireMorphMap();
});

it('allows class names by default and after disabling the guard', function () {
    expect(Storyfeed::requiresFeedableMorphMap())->toBeFalse();
    $model = new Stray;
    $model->id = 123;
    expect(Storyfeed::activity('created', $model)->publish()->object_type)->toBe(Stray::class);
    Storyfeed::requireFeedableMorphMap();
    Storyfeed::requireFeedableMorphMap(false);
    expect(Storyfeed::activity('updated', $model)->publish()->exists)->toBeTrue();
});

it('rejects unaliased Feedable models in every role', function (string $role, bool $fake) {
    Storyfeed::requireFeedableMorphMap();
    if ($fake) {
        Storyfeed::fake();
    }
    $model = new Stray;
    $model->id = 123;
    $pending = Storyfeed::activity('created');
    $role === 'objects' ? $pending->objects([$model]) : $pending->{$role}($model);
    expect(fn () => $pending->publish())->toThrow(FeedableMorphMapViolation::class,
        "Add Relation::morphMap(['stray' => \\".Stray::class.'::class]);');
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'objects'])->with([false, true]);

it('allows aliased models and package-owned aliases', function () {
    Storyfeed::requireFeedableMorphMap();
    Relation::morphMap(['customer' => Customer::class], false);
    $customer = Customer::create(['name' => 'Customer']);
    expect(Storyfeed::activity('created', $customer)->actor('Importer')->publish()->actor_type)->toBe('storyfeed.party');
    $tombstone = new FeedTombstone;
    $tombstone->id = 123;
    expect(Storyfeed::activity('removed', $tombstone)->publish()->object_type)->toBe('storyfeed.tombstone')
        ->and(MorphResolver::classFor('storyfeed.tombstone'))->toBe(FeedTombstone::class);
});

it('reports which setting requires aliases', function (bool $laravel) {
    Relation::requireMorphMap($laravel);
    Storyfeed::requireFeedableMorphMap(! $laravel);
    $finding = Storyfeed::doctor(['surface'])->withCode('surface.unaliased')->firstWhere('subject.model', Stray::class);
    expect($finding->message)->toContain($laravel ? 'Relation::requireMorphMap()' : 'Storyfeed::requireFeedableMorphMap()');
})->with([false, true]);

it('does not report class names as unaliased when aliases are optional', function () {
    expect(Storyfeed::doctor(['surface'])->has('surface.unaliased'))->toBeFalse();
});

it('refuses cache writes only when aliases are required', function (string $setting) {
    Story::for(Customer::class)->verb('created')->headline(':object was created');
    Relation::requireMorphMap($setting === 'laravel');
    Storyfeed::requireFeedableMorphMap($setting === 'storyfeed');
    $this->artisan('storyfeed:cache')->assertExitCode($setting === 'off' ? 0 : 1);
    expect(file_exists(app(StoryManifest::class)->path()))->toBe($setting === 'off');
})->with(['off', 'laravel', 'storyfeed']);

it('checks the resolved default actor', function () {
    $actor = new Stray;
    $actor->id = 123;
    Storyfeed::resolveActorUsing(fn () => $actor);
    Storyfeed::requireFeedableMorphMap();
    expect(fn () => Storyfeed::activity('created')->publish())->toThrow(FeedableMorphMapViolation::class);
});

it('keeps plain models outside the opt-in guard until registered as Feedable', function () {
    Relation::morphMap([], false);
    Storyfeed::requireFeedableMorphMap();
    $photo = new Photo;
    $photo->id = 123;
    expect(Storyfeed::activity('created', $photo)->publish()->exists)->toBeTrue();
    Storyfeed::feedable($photo::class);
    expect(fn () => Storyfeed::activity('updated', $photo)->publish())->toThrow(FeedableMorphMapViolation::class);
    config()->set('storyfeed.discovery.paths', []);
    $this->artisan('storyfeed:cache')->expectsOutputToContain($photo::class)->assertFailed();
});

it('removes a stale manifest when required aliases are missing', function () {
    Story::for(Customer::class)->verb('created')->headline(':object was created');
    $this->artisan('storyfeed:cache')->assertSuccessful();
    Storyfeed::requireFeedableMorphMap();
    $this->artisan('storyfeed:cache')->expectsOutputToContain(Stray::class)->assertFailed();
    expect(file_exists(app(StoryManifest::class)->path()))->toBeFalse();
});

it('caches with the guard enabled when discovered models have aliases', function () {
    config()->set('storyfeed.discovery.paths', [dirname(__DIR__, 2).'/workbench/app']);
    Storyfeed::requireFeedableMorphMap();
    Story::for(Customer::class)->verb('created')->headline(':object was created');
    $this->artisan('storyfeed:cache')->assertSuccessful();
});
