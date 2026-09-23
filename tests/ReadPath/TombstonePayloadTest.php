<?php

use Illuminate\Support\Carbon;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Support\TombstoneRules;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/** The first node of the feed, as a renderer receives it. */
function tombstonedFeedNode(): array
{
    return Storyfeed::feed()->get()->toArray()['items'][0];
}

/** Every node of the feed whose verb is the one given. */
function tombstonedFeedNodes(string $verb): array
{
    return array_values(array_filter(Storyfeed::feed()->get()->toArray()['items'], fn (array $node) => $node['verb'] === $verb));
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');

    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

afterEach(fn () => Carbon::setTestNow());

it('tells a tombstoned entity from a degraded one and an anonymous one', function () {
    Storyfeed::anonymous()->verb('confirm', $this->delivery)->to($this->acme)->publish();

    // Degraded: a live entity with no snapshot.
    Snapshot::query()->where('model_type', 'customer')->delete();

    $this->delivery->delete();

    $node = tombstonedFeedNode();

    // Anonymous: no entity at all.
    expect($node['actor'])->toBeNull()
        // Degraded: the model's own type, no label, no tombstone.
        ->and($node['target']['type'])->toBe('customer')
        ->and($node['target']['label'])->toBeNull()
        ->and($node['target']['tombstone'])->toBeNull()
        // Tombstoned: the tombstone alias, no label, no link, and the facts.
        ->and($node['object']['type'])->toBe('storyfeed.tombstone')
        ->and($node['object']['id'])->toBe((string) FeedTombstone::sole()->id)
        ->and($node['object']['label'])->toBeNull()
        ->and($node['object']['url'])->toBeNull()
        ->and($node['object']['tombstone'])->toBe([
            'formerType' => 'delivery',
            'deleted' => '2026-09-23T12:00:00.000000Z',
            'approximate' => false,
            'removedBy' => null,
        ]);
});

it('carries a tombstone\'s kept label', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $tombstone = FeedTombstone::sole();
    $tombstone->forceFill(['label' => 'Delivery #TN-1'])->save();
    (new SnapshotEntity)($tombstone);

    expect(tombstonedFeedNode()['object'])
        ->label->toBe('Delivery #TN-1')
        ->tombstone->formerType->toBe('delivery');
});

it('marks a deletion the trickle found as approximate', function () {
    $dish = Dish::create(['name' => 'Carrot Soup']);
    Storyfeed::activity()->actor($this->ines)->verb('cook', $dish)->publish();

    Dish::query()->whereKey($dish->id)->delete();
    (new TrickleSnapshots)();

    expect(tombstonedFeedNode()['object']['tombstone'])
        ->formerType->toBe('dish')
        ->approximate->toBeTrue();
});

it('names the tombstoned roles, and calls a deleted object redundant', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->to($this->acme)->publish();

    expect(tombstonedFeedNode())->tombstoned->toBe([])->redundant->toBeFalse();

    $this->delivery->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['object'])->redundant->toBeTrue();
});

it('keeps an activity whose target or actor was deleted from being redundant', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->to($this->acme)->publish();

    $this->acme->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['target'])->redundant->toBeFalse();

    $this->ines->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['actor', 'target'])->redundant->toBeFalse();
});

it('does not call a removal verb redundant when its object is gone', function (string $verb) {
    Storyfeed::activity()->actor($this->ines)->verb($verb, $this->delivery)->publish();

    $this->delivery->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['object'])->redundant->toBeFalse();
})->with([
    'registered as Delete' => 'delete',
    'a Storyfeed\Verb case (Remove)' => 'archive',
    'a Storyfeed\Verb case (Undo)' => 'void',
    'a Storyfeed\Verb case (Reject)' => 'decline',
]);

it('follows an explicit rule, looked up by the deleted model\'s type', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->to($this->acme)->publish();

    app(TombstoneRules::class)->set('delivery.confirm', ['target']);
    app(TombstoneRules::class)->set('*.confirm', []);

    $this->acme->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['target'])->redundant->toBeTrue();

    $this->acme->restore();
    $this->delivery->delete();

    expect(tombstonedFeedNode())->tombstoned->toBe(['object'])->redundant->toBeFalse();
});

it('resolves rules on the type → verb ladder, with removals and the default beneath', function () {
    $rules = app(TombstoneRules::class);

    expect($rules->constitutiveRoles('order', 'place'))->toBe(['object'])
        ->and($rules->constitutiveRoles('order', 'delete'))->toBe([])
        ->and($rules->constitutiveRoles(null, 'place'))->toBe(['object']);

    $rules->set('*.*', ['object', 'result']);
    expect($rules->constitutiveRoles('order', 'place'))->toBe(['object', 'result'])
        // An explicit rule beats the removal default.
        ->and($rules->constitutiveRoles('order', 'delete'))->toBe(['object', 'result']);

    $rules->set('*.place', ['target']);
    expect($rules->constitutiveRoles('order', 'place'))->toBe(['target']);

    $rules->set('order.*', []);
    expect($rules->constitutiveRoles('order', 'place'))->toBe([])
        ->and($rules->constitutiveRoles('dish', 'place'))->toBe(['target']);

    $rules->set('order.place', ['object']);
    expect($rules->constitutiveRoles('order', 'place'))->toBe(['object'])
        ->and($rules->constitutiveRoles(null, 'place'))->toBe(['target']);
});

it('keeps the deleted model\'s headline and glyph', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object', '*.confirm' => ':actor confirmed something']);
    Storyfeed::icons(['delivery.confirm' => 'truck']);
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    expect(tombstonedFeedNode())
        ->headline_template->toBe(':actor confirmed :object')
        ->glyph->toBe('truck');
});

it('counts a group\'s tombstoned entities beside distinct, and samples live ones first', function () {
    config(['storyfeed.grouping.sample_limits.actor' => 2, 'storyfeed.grouping.sample_limits.object' => 2]);

    $users = collect(['Ada', 'Bo', 'Cy', 'Di'])->map(fn (string $name) => User::create(['name' => $name, 'email' => "{$name}@example.com"]));
    $deliveries = $users->map(fn (User $user, int $i) => Delivery::create(['tracking_number' => 'TN-'.($i + 10)]));

    // Oldest first, so the last two actors are the newest members.
    foreach ($users as $i => $user) {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00')->addMinutes($i));
        Storyfeed::activity()->actor($user)->verb('confirm', $deliveries[$i])->to($this->acme)->publish();
    }

    $users[2]->delete();
    $users[3]->delete();
    $deliveries[3]->delete();

    $group = tombstonedFeedNodes('confirm')[0];

    expect($group)
        ->kind->toBe('group')
        ->axis->toBe('actors')
        ->count->toBe(4)
        ->tombstoned->toBe(['actor', 'object'])
        // Two of four orders are live: the group is not redundant.
        ->redundant->toBeFalse()
        ->and($group['distinct']['actors'])->toBe(4)
        ->and($group['distinct_tombstoned']['actors'])->toBe(2)
        ->and($group['distinct_tombstoned']['objects'])->toBe(1)
        ->and($group['distinct_tombstoned']['targets'])->toBe(0)
        // The newest two actors are tombstones; the sample shows the live two.
        ->and(array_column($group['sample']['actors'], 'label'))->toBe(['Bo', 'Ada'])
        ->and(array_column($group['sample']['objects'], 'type'))->toBe(['delivery', 'delivery']);
});

it('calls a whole group redundant when its pinned object is gone', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $group = tombstonedFeedNode();

    expect($group)
        ->kind->toBe('group')
        ->tombstoned->toBe(['object'])
        ->redundant->toBeTrue()
        ->and($group['distinct']['objects'])->toBe(1)
        ->and($group['distinct_tombstoned']['objects'])->toBe(1)
        ->and(array_column($group['children'], 'redundant'))->toBe([true, true]);
});

it('serializes a tombstoned entity as an AS2 Tombstone, and an actor per FEP-e965', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();
    $this->ines->delete();

    $document = app(ActivitySerializer::class)->activity(Activity::query()->sole());

    expect($document['object'])->toBe([
        'type' => 'Tombstone',
        'formerType' => Storyfeed::objectTypeValue('delivery'),
        'deleted' => '2026-09-23T12:00:00Z',
    ])->and($document['actor'])->toBe([
        'type' => [Storyfeed::objectTypeValue('user'), 'Tombstone'],
        'deleted' => '2026-09-23T12:00:00Z',
    ]);
});
