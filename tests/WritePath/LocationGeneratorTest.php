<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Sources\SourceItem;
use Storyfeed\Support\Chronology;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('adds nullable location and generator triples on upgrade with only identity indexes and no backfill', function (string $identity) {
    $table = 'legacy_location_activities';
    config(['storyfeed.tables.activities' => $table]);

    try {
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('verb');
        });
        DB::table($table)->insert(['id' => 1, 'verb' => 'historical']);
        $before = (array) DB::table($table)->first();
        $migration = include __DIR__.'/../../database/migrations/add_location_and_generator_to_feed_activities_table.php.stub';
        DB::enableQueryLog();
        $migration->up();
        $migration->up();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            expect(strtolower(ltrim($query['query'])))->not->toStartWith('update ');
        }

        $expected = $before;
        foreach (['location', 'generator'] as $role) {
            $expected[$role.'_type'] = null;
            $expected[$role.'_id'] = null;
            $expected['cached_'.$role.'_id'] = null;
            expect(Schema::hasIndex($table, [$role.'_type', $role.'_id']))->toBeTrue()
                ->and(Schema::hasIndex($table, ['cached_'.$role.'_id']))->toBeFalse();
        }
        expect((array) DB::table($table)->first())->toBe($expected)
            ->and(Schema::getIndexes($table))->toHaveCount(3); // PK and two morph indexes.

        foreach (['location', 'generator'] as $role) {
            DB::table($table)->where('id', 1)->update([$role.'_type' => 'customer', $role.'_id' => $identity]);
            expect((string) DB::table($table)->value($role.'_id'))->toBe((string) $identity);
        }
        $migration->down();
        $migration->down();
        expect((array) DB::table($table)->first())->toBe($before);
    } finally {
        DB::disableQueryLog();
    }
})->with(['42', '018f3010-791c-7e90-9800-c90214d7b444', '01J00000000000000000000000']);

it('records where an act happened with at(), and what produced it with generator()', function () {
    $talk = Customer::create(['name' => 'GPUG Waterloo']);
    Story::fallback()->headline(':actor unveiled :object at :location from :generator');

    $activity = Storyfeed::activity('unveil', 'Storyfeed')->by('Jasper')->at($talk)->generator('Claude')->publish();
    $node = app(NodePresenter::class)->activityNode($activity->fresh());

    expect($activity->location->is($talk))->toBeTrue()
        ->and($activity->generator->name)->toBe('Claude')
        ->and($node['location']['label'])->toBe('GPUG Waterloo')
        ->and($node['generator']['label'])->toBe('Claude')
        ->and($node['headline_template'])->toBe(':actor unveiled :object at :location from :generator')
        ->and(serialize_one($activity)['summary'])->toBe('Jasper unveiled Storyfeed at GPUG Waterloo from Claude')
        ->and(serialize_one($activity)['location']['name'])->toBe('GPUG Waterloo')
        ->and(serialize_one($activity)['generator']['name'])->toBe('Claude')
        ->and(Storyfeed::feed()->location($talk)->log()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->generator('Claude')->log()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->locationType(Customer::class)->log()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->involving($talk)->log()->get()->items())->toHaveCount(1);
});

it('takes location and generator through record() and enum verbs', function () {
    $delivery = Delivery::create(['tracking_number' => 'W78']);

    $records = [
        Storyfeed::record('confirm', object: $delivery, location: 'Dock 4', generator: 'Scanner app'),
        ActivityVerb::Confirm->record(object: $delivery, location: 'Dock 4', generator: 'Scanner app'),
        ActivityVerb::Confirm->at('Dock 4')->generator('Scanner app')->publish(),
    ];

    foreach ($records as $record) {
        expect($record->location->name)->toBe('Dock 4')
            ->and($record->generator->name)->toBe('Scanner app');
    }
});

it('keeps the derived id of a source item that names neither new role', function () {
    $item = SourceItem::make('release', '2026-10-09 12:00:00', actor: 'Storyfeed', object: ['type' => 'release', 'label' => 'v0.20.0']);

    // The id as it was derived before location and generator existed.
    $roles = array_fill_keys(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'], null);
    $roles['actor'] = ['storyfeed.party', 'storyfeed'];
    $roles['object'] = ['release', 'v0.20.0'];
    $before = substr(hash('sha256', json_encode(['release', Chronology::stamp($item->publishedAt), $roles, []], JSON_THROW_ON_ERROR)), 0, 26);

    expect($item->identity())->toBe($before)
        ->and(SourceItem::make('release', '2026-10-09 12:00:00', actor: 'Storyfeed', object: ['type' => 'release', 'label' => 'v0.20.0'], location: 'Waterloo')->identity())
        ->not->toBe($before);
});
