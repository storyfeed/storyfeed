<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Delivery;

function sampleLimitSlice(): GroupSlice
{
    $members = collect(range(1, 8))->map(function ($i) {
        $activity = new Activity(['verb' => 'inspect', 'published_at' => now()]);
        foreach (['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'] as $role) {
            // Seven distinct identities, with a duplicate at the end.
            $activity->setAttribute($role.'_type', 'user');
            $activity->setAttribute($role.'_id', min($i, 7));
        }

        return $activity;
    });

    return GroupSlice::group('repeat', 'limits', 12, $members, array_fill_keys([
        'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument',
    ], 10));
}

it('samples three entities for every role', function () {
    $node = app(NodePresenter::class)->groupNode(sampleLimitSlice());
    foreach ($node['sample'] as $entities) {
        expect($entities)->toHaveCount(3);
    }
});

it('changes only the configured role while preserving children totals and order', function ($role) {
    $presenter = app(NodePresenter::class);
    $slice = sampleLimitSlice();
    $default = $presenter->groupNode($slice);
    config(['storyfeed.grouping.sample_limits.'.$role => 6]);
    $node = $presenter->groupNode($slice);

    foreach ($node['sample'] as $key => $entities) {
        if ($key === $role.'s') {
            expect($entities)->toHaveCount(6)
                ->and(array_map('strval', array_column($entities, 'id')))->toBe(['1', '2', '3', '4', '5', '6']);
        } else {
            expect($entities)->toBe($default['sample'][$key]);
        }
    }
    unset($node['sample'], $default['sample']);
    expect($node)->toBe($default);
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument']);

it('falls back to three for a missing or invalid role limit', function ($limit) {
    config(['storyfeed.grouping.sample_limits' => ['object' => $limit]]);
    $node = app(NodePresenter::class)->groupNode(sampleLimitSlice());
    foreach ($node['sample'] as $entities) {
        expect($entities)->toHaveCount(3);
    }
})->with([null, 0, -1, 'six', '6', 1.5]);

it('takes only distinct loaded entities even when the configured limit is larger', function () {
    config(['storyfeed.grouping.sample_limits.object' => 20]);
    $node = app(NodePresenter::class)->groupNode(sampleLimitSlice());
    expect($node['sample']['objects'])->toHaveCount(7)
        ->and($node['distinct']['objects'])->toBe(10)
        ->and($node['children'])->toHaveCount(8)
        ->and($node['count'])->toBe(12)
        ->and($node['children_truncated'])->toBeTrue();
});

it('shows six composite objects through the feed while retaining pinned roles', function () {
    config(['storyfeed.grouping.sample_limits.object' => 6]);
    $files = collect(range(1, 6))->map(fn ($i) => Delivery::create(['tracking_number' => "File-{$i}"]));
    Storyfeed::activity('upload')->actor('Importer')->objects($files)->publish();
    $node = Storyfeed::feed()->get()->items()[0];
    expect($node['sample']['objects'])->toHaveCount(6)
        ->and($node['actor']['label'])->toBe('Importer')
        ->and($node['sample']['actors'])->toHaveCount(1);

    config(['storyfeed.grouping.children_limit' => 2]);
    $capped = Storyfeed::feed()->get()->items()[0];
    expect($capped['sample']['objects'])->toHaveCount(2)
        ->and($capped['distinct']['objects'])->toBe(6)
        ->and($capped['children_truncated'])->toBeTrue();
});
