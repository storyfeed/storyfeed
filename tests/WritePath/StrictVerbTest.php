<?php

use Storyfeed\Exceptions\UnknownVerb;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

beforeEach(fn () => config()->set('storyfeed.verbs.strict', true));

it('rejects verbs that resolve to no registry entry', function () {
    expect(fn () => Storyfeed::activity('confrim', Delivery::create(['tracking_number' => 'TN-1'])))
        ->toThrow(UnknownVerb::class);

    expect(Activity::query()->count())->toBe(0);
});

it('accepts built-in verbs', function () {
    expect(Storyfeed::activity('create')->publish()->verb)->toBe('create');
});

it('accepts registered verbs', function () {
    Storyfeed::verbs(['confirm' => 'Update']);

    expect(Storyfeed::activity('confirm')->publish()->verb)->toBe('confirm');
});

it('accepts verbs from a registered enum vocabulary', function () {
    Storyfeed::verbs(ActivityVerb::class);

    expect(ActivityVerb::Upload->publish()->verb)->toBe('upload');
});

it('stays permissive when strict mode is off', function () {
    config()->set('storyfeed.verbs.strict', false);

    expect(Storyfeed::activity('confrim')->publish()->verb)->toBe('confrim');
});
