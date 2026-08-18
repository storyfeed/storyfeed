<?php

namespace Workbench\App\Feeds;

use Storyfeed\Feed;
use Storyfeed\FeedBuilder;

/** A global feed: no subject, so `AdminFeed::make()` simply works. */
class AdminFeed extends Feed
{
    public function define(FeedBuilder $feed): void
    {
        $feed->except(['order.margin_note'])->summary();
    }
}
