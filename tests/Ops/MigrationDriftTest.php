<?php

use Illuminate\Support\Facades\Schema;

/**
 * The shape column once shipped only inside the create stub, so any install
 * that had already run it never got the column — and every snapshot write
 * threw SQLSTATE[42S22] at runtime while the deploy looked green (found in
 * production: a feed frozen for hours, reads alive, writes dead). The doctor
 * is the mechanical detector for that drift.
 */
it('doctor flags a table missing a column the write path depends on', function () {
    Schema::table('feed_snapshots', function ($table) {
        $table->dropIndex(['model_type', 'shape']);
        $table->dropColumn('shape');
    });

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('missing the `shape` column — writes touching it will throw at runtime. Run the migrations Storyfeed v0.22 publishes first')
        ->assertSuccessful();

    Schema::table('feed_snapshots', function ($table) {
        $table->string('shape', 40)->nullable();
        $table->index(['model_type', 'shape']);
    });

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('missing the `shape` column')
        ->assertSuccessful();
});
