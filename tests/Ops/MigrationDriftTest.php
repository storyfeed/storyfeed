<?php

use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Delivery;

/**
 * The shape column originally shipped only inside the create stub, so any
 * install that had already run it never got the column — and every snapshot
 * write threw SQLSTATE[42S22] at runtime while the deploy looked green
 * (found in production: a feed frozen for hours, reads alive, writes dead).
 * These tests pin the repair path AND the policy: create stubs are frozen
 * once consumers exist; schema changes ship as guarded additive stubs.
 */
function shapeAddMigration(): object
{
    return include __DIR__.'/../../database/migrations/add_shape_to_feed_snapshots_table.php.stub';
}

it('repairs an existing install that predates the shape column', function () {
    // Simulate the install that ran the pre-shape create stub.
    Schema::table('feed_snapshots', function ($table) {
        $table->dropIndex(['model_type', 'shape']);
        $table->dropColumn('shape');
    });

    // This is the frozen-feed failure: the write path stamps `shape`
    // unconditionally, so a missing column kills every snapshot write.
    expect(fn () => Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish())
        ->toThrow(Exception::class);

    shapeAddMigration()->up();

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    expect(Snapshot::query()->where('model_type', 'delivery')->sole()->shape)
        ->toMatch('/^[0-9a-f]{40}$/');
});

it('is idempotent on installs whose create stub already added the column', function () {
    // TestCase already ran both the create stub (with shape) and the add
    // stub; a re-run must be a no-op, not a duplicate-column error.
    shapeAddMigration()->up();

    expect(Schema::hasColumn('feed_snapshots', 'shape'))->toBeTrue();
});

it('doctor flags a table missing a column the write path depends on', function () {
    Schema::table('feed_snapshots', function ($table) {
        $table->dropIndex(['model_type', 'shape']);
        $table->dropColumn('shape');
    });

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('missing the `shape` column')
        ->assertSuccessful();

    shapeAddMigration()->up();

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('missing the `shape` column')
        ->assertSuccessful();
});
