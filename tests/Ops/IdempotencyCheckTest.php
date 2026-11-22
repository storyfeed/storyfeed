<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

it('warns when an idempotent verb has no unique index to hit, and not once the app adds one', function () {
    Story::for(Delivery::class)->verb('ship')->idempotent();
    Story::for(Delivery::class)->verb('confirm');

    $finding = Storyfeed::doctor(['idempotent'])->withCode('idempotent.no_index')->first();

    expect($finding)->not->toBeNull()
        ->and($finding->subject['verbs'])->toBe((new Delivery)->getMorphClass().'.ship');

    $table = config('storyfeed.tables.activities', 'feed_activities');
    Schema::table($table, fn (Blueprint $t) => $t->unique(['verb', 'object_type', 'object_id'], 'idempotency_check_unique'));

    try {
        expect(Storyfeed::doctor(['idempotent'])->withCode('idempotent.no_index'))->toHaveCount(0);
    } finally {
        Schema::table($table, fn (Blueprint $t) => $t->dropUnique('idempotency_check_unique'));
    }
});

it('lists whether a definition is idempotent and the noun it counts with', function () {
    Story::for(Delivery::class)->verb('ship')->idempotent()->noun('parcel|parcels');
    Story::for(Delivery::class)->verb('confirm');

    Artisan::call('storyfeed:list', ['--json' => true]);
    $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

    expect($rows['ship']['idempotent'])->toBeTrue()
        ->and($rows['ship']['noun'])->toContain('parcel')
        ->and($rows['confirm']['idempotent'])->toBeFalse()
        ->and($rows['confirm']['noun'])->toBeNull();
});
