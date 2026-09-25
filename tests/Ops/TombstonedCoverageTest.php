<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Diagnostics\Checks\Retention;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * A deleted model's activities are repointed to the tombstone, so their
 * stored `object_type` is `storyfeed.tombstone`. The read path asks the
 * registries about the deleted model's own alias (the tombstone's
 * formerType), and the doctor has to ask the same question. Found on a
 * production doctor: `storyfeed.tombstone.create` reported as an error-level
 * `grammar.missing` while the feed rendered `order.create`'s headline.
 */

beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->delivery = Delivery::create(['tracking_number' => 'TN-1']);
});

function declareConfirm(): void
{
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object')->icon('check');
}

it('renders a tombstoned row with its former type\'s headline', function () {
    declareConfirm();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    // A string entry ships as the template for the renderer to fill; the
    // tombstone fills `:object` with no label, as it does on every surface.
    expect($node['object']['type'])->toBe(FeedTombstone::MORPH_ALIAS)
        ->and($node['object']['tombstone']['formerType'])->toBe('delivery')
        ->and($node['headline_template'])->toBe(':actor confirmed :object')
        ->and($node['glyph'])->toBe('check');
});

it('finds grammar and an icon for a tombstoned row under its former type', function () {
    declareConfirm();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $report = Storyfeed::doctor(['grammar']);

    expect($report->has('grammar.missing'))->toBeFalse()
        ->and($report->has('grammar.icon_missing'))->toBeFalse();

    $this->artisan('storyfeed:doctor', ['--only' => ['grammar']])
        ->doesntExpectOutputToContain('storyfeed.tombstone')
        ->assertSuccessful();
});

it('still reports a tombstoned row whose former type has no grammar, under that type', function () {
    $dish = Dish::create(['name' => 'Carrot Soup']);
    Storyfeed::activity()->actor($this->ines)->verb('cook', $dish)->publish();

    $dish->delete();

    $finding = Storyfeed::doctor(['grammar'])->withCode('grammar.missing')->sole();

    expect($finding->subject)->toBe(['type' => 'dish', 'verb' => 'cook'])
        ->and($finding->message)->toContain('`dish.cook`');
});

it('finds an actorless headline under the former type', function () {
    Story::for(Delivery::class)->verb('confirm')->anonymousHeadline('Confirmed :object');
    Storyfeed::anonymous()->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    expect(Storyfeed::doctor(['actorless'])->all())->toBeEmpty();
});

it('counts a tombstoned row\'s roles toward its former type\'s template', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object for :target');

    // Only the deleted delivery's row ever had a target.
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->to(Customer::create(['name' => 'Acme']))->publish();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    $this->delivery->delete();

    expect(Storyfeed::doctor(['roles'])->has('roles.never_carried'))->toBeFalse();
});

it('counts a deleted model\'s pair as recorded', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object');
    Story::for(Customer::class)->verb('confirm')->headline(':actor confirmed :object');

    Storyfeed::activity()->actor($this->ines)->verb('confirm', Customer::create(['name' => 'Acme']))->publish();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    expect(Storyfeed::doctor(['verbs'])->withCode('grammar.unrecorded'))->toBeEmpty();
});

it('reads a removal declaration on the former type', function () {
    Story::for(Delivery::class)->verb('cancel')->missing();
    Storyfeed::activity()->actor($this->ines)->verb('cancel', $this->delivery)->publish();

    $this->delivery->delete();

    expect(Storyfeed::doctor(['removals'])->has('removals.unclassified'))->toBeFalse();
});

it('reads a tombstoned row\'s retention on its former type, as prune does', function () {
    Story::for(Delivery::class)->verb('view')->keepFor('90 days');
    Storyfeed::activity()->actor($this->ines)->verb('view', $this->delivery)->publish();

    $this->delivery->delete();

    $tombstone = FeedTombstone::sole();
    $rows = array_map(fn () => [
        'uid' => (string) str()->ulid(), 'verb' => 'view', 'object_type' => $tombstone->getMorphClass(),
        'object_id' => $tombstone->id, 'published_at' => now()->subDay()->format('Y-m-d H:i:s.u'),
    ], range(1, Retention::HIGH_VOLUME));

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table((new Activity)->getTable())->insert($chunk);
    }

    expect(Storyfeed::doctor(['retention'])->has('retention.unbounded'))->toBeFalse();
});

it('checks a tombstoned row against its former type\'s role constraints', function () {
    Storyfeed::activity('ship', $this->delivery)->actor('Stripe')->publish();

    $this->delivery->delete();

    Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

    expect(Storyfeed::doctor(['role_constraints'])->withCode('role_constraints.violated')->sole()->subject)
        ->toMatchArray(['type' => 'delivery', 'verb' => 'ship', 'role' => 'actor', 'rows' => 1]);
});

it('reads keep-latest on the former type', function () {
    Story::for(Delivery::class)->verb('save')->keepLatest();

    Storyfeed::activity('save', $this->delivery)->publishedAt('2026-09-20 09:00:00')->publish();
    Storyfeed::activity('save', $this->delivery)->publishedAt('2026-09-21 09:00:00')->publish();

    $this->delivery->delete();

    expect(Activity::query()->onlyTrashed()->sole()->object_type)->toBe(FeedTombstone::MORPH_ALIAS)
        ->and(Storyfeed::doctor(['keep_latest'])->has('keep_latest.undeclared'))->toBeFalse();
});

it('finds a pinned aggregate headline under the former type', function () {
    Story::for(Delivery::class)->verb('place')->grouped(fn (GroupBuilder $group) => $group->repeat(':actor placed :objects'));

    $deliveries = collect(range(1, 3))->map(fn (int $i) => Delivery::create(['tracking_number' => "de-{$i}"]));

    foreach ($deliveries as $delivery) {
        Storyfeed::activity()->actor($this->ines)->verb('place', $delivery)->publish();
    }

    $deliveries->each->delete();

    expect(Storyfeed::doctor(['aggregates'])->has('aggregates.missing'))->toBeFalse();
});
