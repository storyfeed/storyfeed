<?php

use Illuminate\Support\Facades\Schema;

/*
 * The live read's composite-winner arm needs (winner, bucket, hash): without
 * it PostgreSQL 18 scanned all of (activity_id, bucket) at 3M activities,
 * 5.9s instead of 4ms (#275). v0.23's consolidation had dropped it.
 */
it('keeps the winner index on feed_groupings, and the upgrade restores it once', function () {
    expect(Schema::hasIndex('feed_groupings', 'feed_groupings_winner_index'))->toBeTrue();

    $migration = include __DIR__.'/../../database/migrations/add_winner_index_to_feed_groupings_table.php.stub';
    $migration->down();
    expect(Schema::hasIndex('feed_groupings', 'feed_groupings_winner_index'))->toBeFalse();

    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('feed_groupings', 'feed_groupings_winner_index'))->toBeTrue();
});
