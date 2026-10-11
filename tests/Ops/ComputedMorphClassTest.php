<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Tests\Fixtures\ComputedMorph\ScenarioEntity;
use Workbench\App\Models\Customer;

beforeEach(function () {
    Relation::requireMorphMap(false);
    config()->set('storyfeed.discovery.paths', [dirname(__DIR__).'/Fixtures/ComputedMorph', dirname(__DIR__, 2).'/workbench/app']);
    Story::for(Customer::class)->verb('created')->headline(':object was created');
});

afterEach(function () {
    app(StoryManifest::class)->delete();
    Relation::requireMorphMap();
});

it('caches past a Feedable that cannot name its morph class on a blank instance', function (string $setting) {
    Relation::requireMorphMap($setting === 'laravel');
    Storyfeed::requireFeedableMorphMap($setting === 'storyfeed');

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain("Can't check the morph alias of ".ScenarioEntity::class.': TypeError')
        ->assertSuccessful();

    expect(file_exists(app(StoryManifest::class)->path()))->toBeTrue();
})->with(['laravel', 'storyfeed']);

it('still refuses an unaliased model beside one it cannot check', function () {
    Relation::morphMap([], false);
    Storyfeed::requireFeedableMorphMap();

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain(ScenarioEntity::class)
        ->expectsOutputToContain(Customer::class)
        ->assertFailed();
});

it('reports the class in the surface check and carries on', function (bool $required) {
    Storyfeed::requireFeedableMorphMap($required);

    $report = Storyfeed::doctor(['surface']);
    $finding = $report->withCode('surface.uncheckable')->firstWhere('subject.model', ScenarioEntity::class);

    expect($finding->message)->toContain('TypeError')
        ->and($finding->severity->value)->toBe($required ? 'warning' : 'info')
        ->and($report->has('surface.unaliased'))->toBeFalse()
        ->and($report->withCode('surface.uncheckable')->count())->toBe(1);
})->with([false, true]);
