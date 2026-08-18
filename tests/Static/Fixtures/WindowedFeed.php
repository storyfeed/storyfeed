<?php

namespace Storyfeed\Tests\Static\Fixtures;

use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;

/** A subject plus something optional — the two-parameter case. */
class WindowedFeed extends Feed
{
    public function __construct(protected Customer $customer, protected ?string $since = null) {}

    public function define(FeedBuilder $feed): void
    {
        $feed->log();
    }

    protected function scope(FeedBuilder $feed): void
    {
        $feed->context($this->customer);
    }
}
