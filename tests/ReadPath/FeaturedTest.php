<?php

use Storyfeed\Exceptions\IncompleteActivity;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
});

function featuredNode(Activity $activity): array
{
    return app(NodePresenter::class)->activityNode($activity->fresh());
}

/** One `ping` group of $count members by $actor, each on its own customer. */
function featuredGroup(User $actor, int $count, ?Closure $tap = null): array
{
    foreach (range(1, $count) as $i) {
        $pending = Storyfeed::activity('ping', Customer::create(['name' => "Customer {$i}"]))->actor($actor)
            ->publishedAt(now()->subSeconds($count + 1 - $i));
        ($tap ?? fn ($pending) => $pending)($pending, $i);
        $pending->publish();
    }

    return Storyfeed::feed()->get()->toArray()[0];
}

it('features the object by default', function () {
    $activity = Storyfeed::activity('unveil', 'Storyfeed')->by('Jasper')->publish();

    expect(featuredNode($activity)['featured'])->toBe('object')
        ->and($activity->fresh()->featured)->toBe('object');
});

it('features the role an activity names', function (string $method, string $role) {
    $activity = Storyfeed::activity('unveil', 'Storyfeed')->by('Jasper')->target('Waterloo')->origin('Toronto')
        ->resulting('Applause')->using('Projector')->at('GPUG')->generator('Keynote')
        ->{$method}()->publish();

    expect(featuredNode($activity)['featured'])->toBe($role);
})->with([
    ['featuringObject', 'object'], ['featuringActor', 'actor'], ['featuringTarget', 'target'],
    ['featuringOrigin', 'origin'], ['featuringResult', 'result'], ['featuringInstrument', 'instrument'],
    ['featuringLocation', 'location'], ['featuringGenerator', 'generator'],
]);

it('draws no entity body with withoutFeature()', function () {
    $activity = Storyfeed::activity('reprice', 'Widget')->withoutFeature()->publish();

    expect(featuredNode($activity)['featured'])->toBeNull()
        ->and($activity->fresh()->featured)->toBeNull();
});

it('lets the last call win, so featuringObject() undoes an earlier one', function () {
    $activity = Storyfeed::activity('unveil', 'Storyfeed')->at('GPUG')->featuringLocation()->featuringObject()->publish();

    expect(featuredNode($activity)['featured'])->toBe('object');
});

it('takes a verb default that an activity can override', function () {
    Story::verb('unveil')->headline(':actor unveiled :object at :location')->featuringLocation();
    Story::verb('reprice')->headline(':object was repriced')->withoutFeature();

    $byDefault = Storyfeed::activity('unveil', 'Storyfeed')->at('GPUG')->publish();
    $overridden = Storyfeed::activity('unveil', 'Storyfeed')->at('GPUG')->featuringObject()->publish();
    $without = Storyfeed::activity('reprice', 'Widget')->publish();

    expect(featuredNode($byDefault)['featured'])->toBe('location')
        ->and(featuredNode($overridden)['featured'])->toBe('object')
        ->and(featuredNode($without)['featured'])->toBeNull();
});

it('throws when the featured role is empty', function () {
    Storyfeed::activity('unveil', 'Storyfeed')->featuringLocation()->publish();
})->throws(IncompleteActivity::class, 'The [unveil] activity features its location, which is empty.');

it('throws when a verb features a role the activity left empty', function () {
    Story::verb('unveil')->headline(':actor unveiled :object')->featuringLocation();

    Storyfeed::activity('unveil', 'Storyfeed')->publish();
})->throws(IncompleteActivity::class, 'features its location');

it('never throws for an empty object, the default', function () {
    $activity = Storyfeed::activity('ping')->by('Jasper')->featuringObject()->publish();

    expect(featuredNode($activity)['featured'])->toBe('object')
        ->and(featuredNode($activity)['object'])->toBeNull();
});

it('forwards the featured methods from verb enums', function () {
    $activity = ActivityVerb::Confirm->at('Dock 4')->featuringLocation()->publish();

    expect(featuredNode($activity)['featured'])->toBe('location');
});

it('reads a row without the column as featuring its object', function () {
    $activity = Storyfeed::activity('unveil', 'Storyfeed')->publish()->fresh();
    $attributes = $activity->getAttributes();
    unset($attributes['featured']);
    $activity->setRawAttributes($attributes, true);

    expect(app(NodePresenter::class)->activityNode($activity)['featured'])->toBe('object');
});

it('samples one featured entity per member, newest first, and counts every member', function () {
    $node = featuredGroup($this->sally, 5);

    expect($node['kind'])->toBe('group')
        ->and($node['featured'])->toBeNull() // five customers: no one entity to draw
        ->and(array_column($node['sample']['featured'], 'label'))->toBe(['Customer 5', 'Customer 4', 'Customer 3'])
        ->and($node['distinct']['featured'])->toBe(5)
        ->and($node['distinct_tombstoned']['featured'])->toBe(0);
});

it('features a role on the group when every member features it and the axis pins it', function () {
    $node = featuredGroup($this->sally, 4, fn ($pending) => $pending->featuringActor());

    expect($node['axis'])->toBe('repeat')
        ->and($node['featured'])->toBe('actor')
        ->and($node['actor']['label'])->toBe('Sally')
        // One entry per member: the same entity, repeated.
        ->and(array_column($node['sample']['featured'], 'label'))->toBe(['Sally', 'Sally', 'Sally'])
        ->and($node['distinct']['featured'])->toBe(4);
});

it('counts featured roles across members past the children', function () {
    config(['storyfeed.grouping.children_limit' => 5]);

    $node = featuredGroup($this->sally, 12, fn ($pending) => $pending->featuringActor());

    expect($node['children_truncated'])->toBeTrue()
        ->and($node['featured'])->toBe('actor')
        ->and($node['distinct']['featured'])->toBe(12);
});

it('features nothing on the group when members disagree', function () {
    config(['storyfeed.grouping.children_limit' => 5]);

    // The oldest member, beyond the children, features its object.
    $node = featuredGroup($this->sally, 12, fn ($pending, int $i) => $i === 1 ? $pending : $pending->featuringActor());

    expect($node['featured'])->toBeNull()
        ->and(array_column($node['sample']['featured'], 'label'))->toBe(['Sally', 'Sally', 'Sally'])
        ->and($node['distinct']['featured'])->toBe(12);
});

it('takes no entry from a member that features nothing', function () {
    $node = featuredGroup($this->sally, 4, fn ($pending, int $i) => $i % 2 === 0 ? $pending->withoutFeature() : $pending);

    expect($node['featured'])->toBeNull()
        ->and(array_column($node['sample']['featured'], 'label'))->toBe(['Customer 3', 'Customer 1'])
        ->and($node['distinct']['featured'])->toBe(2);
});

it('draws nothing for a group whose members all feature nothing', function () {
    $node = featuredGroup($this->sally, 3, fn ($pending) => $pending->withoutFeature());

    expect($node['featured'])->toBeNull()
        ->and($node['sample']['featured'])->toBe([])
        ->and($node['distinct']['featured'])->toBe(0);
});

it('caps the strip by sample_limits.featured', function () {
    config(['storyfeed.grouping.sample_limits.featured' => 6]);

    $node = featuredGroup($this->sally, 8);

    expect($node['sample']['featured'])->toHaveCount(6)
        ->and($node['distinct']['featured'])->toBe(8);
});
