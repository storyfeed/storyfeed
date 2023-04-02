<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

it('merges the morphmap from config', function () {
    $packageMorphs = config('storyfeed.morphmap');

    expect($packageMorphs)->toBeArray();

    $morphMap = Relation::morphMap();

    // expect $packageMorphs to be a subset of $morphMap
    foreach ($packageMorphs as $key => $value) {
        expect($morphMap)->toHaveKey($key);
        expect($morphMap[$key])->toBe($value);
    }
});

it('allows customizing the morphmap', function () {
    $packageMorphs = config('storyfeed.morphmap');

    $customMorphs = [];

    foreach ($packageMorphs as $key => $value) {
        $newKey = Str::slug(fake()->unique()->word());
        $customMorphs[$newKey] = $value;
    }

    config()->set('storyfeed.morphmap', $customMorphs);

    $this->refreshApplication();

    expect(config('storyfeed.morphmap'))->toBe($customMorphs);

    $morphMap = Relation::morphMap();

    foreach ($customMorphs as $key => $value) {
        expect($morphMap)->toHaveKey($key);
        expect($morphMap[$key])->toBe($value);
    }
})->skip('WIP');
