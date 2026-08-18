<?php

namespace Workbench\App\Feeds;

use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;

/** define() illegally reads constructor state — doctor must survive it. */
class GreedyFeed extends Feed
{
    public function __construct(protected Customer $customer) {}

    public function define(FeedBuilder $feed): void
    {
        $feed->only(['order.'.$this->customer->id]);
    }

    protected function scope(FeedBuilder $feed): void
    {
        $feed->context($this->customer);
    }
}
