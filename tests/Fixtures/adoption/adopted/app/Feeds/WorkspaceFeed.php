<?php

namespace App\Feeds;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Feed;
use Storyfeed\FeedBuilder;

class WorkspaceFeed extends Feed
{
    public function __construct(protected Model $workspace) {}

    protected function scope(FeedBuilder $feed): void
    {
        $feed->context($this->workspace);
    }

    public function define(FeedBuilder $feed): void
    {
        $feed->log();
    }
}
