<?php

namespace Storyfeed\Listeners;

use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\StoryfeedManager;

/**
 * Publishes the activity a `PublishesToFeed` implementor describes.
 *
 * Registered ONCE, against the interface:
 *
 *   Event::listen(PublishesToFeed::class, PublishFeedActivity::class);
 *
 * Laravel's dispatcher walks `class_implements()` for object events, so this
 * receives every implementor and nothing else. That is the whole reason it is an
 * interface rather than a wildcard listener: a wildcard would be invoked for
 * every event the application dispatches, including every Eloquent lifecycle
 * event, to answer a question `isset()` on one array key already answers.
 *
 * SYNCHRONOUS, inside the dispatch, consistent with every other write path here
 * (publish() already snapshots, groups, batches and curates inline in one
 * transaction). Transaction safety is the EVENT's decision, via Laravel's own
 * `ShouldDispatchAfterCommit` — per-event, native, and visible at the
 * declaration site. A global `storyfeed.events.after_commit` config was
 * considered and rejected: a global answer to a per-event question, and it would
 * make the recording site's behaviour invisible from the code.
 */
class PublishFeedActivity
{
    public function __construct(
        protected StoryfeedManager $storyfeed,
    ) {}

    public function handle(PublishesToFeed $publisher): void
    {
        $this->storyfeed->publishFor($publisher);
    }
}
