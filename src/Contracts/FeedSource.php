<?php

namespace Storyfeed\Contracts;

use Storyfeed\Models\Activity;
use Storyfeed\Sources\SourceItem;

/**
 * Where a feed's activities come from — the driver behind a named source in
 * `storyfeed.sources`, the way a store sits behind a cache and a disk behind
 * the filesystem.
 *
 *   Storyfeed::extend('github', fn ($app, array $config) => new GitHubSource($config));
 *
 *   Storyfeed::feed()->source('roadmap')->live()->get();
 *
 * A source hands over activity-shaped items; Storyfeed reads them the way it
 * reads the database: headlines from the definitions file, bodies, Log and
 * Live grouping, only()/except(), limits and cursors, into the same payload.
 *
 * The `database` source is the default and reads through SQL. Every other
 * source is read in memory, which is why it cannot answer what only stored
 * history knows: involving(), involvingType() and query() throw on one.
 */
interface FeedSource
{
    /**
     * Every item in the source, in any order.
     *
     * @return iterable<SourceItem|array<string, mixed>|Activity>
     */
    public function items(): iterable;
}
