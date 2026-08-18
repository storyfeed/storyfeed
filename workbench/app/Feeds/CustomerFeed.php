<?php

namespace Workbench\App\Feeds;

use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;

/**
 * The shape the whole lane exists for: a customer-facing feed whose SCOPE is
 * part of the declaration, so no call site can render it unscoped.
 */
class CustomerFeed extends Feed
{
    public function __construct(protected Customer $customer) {}

    public function define(FeedBuilder $feed): void
    {
        $feed->only(['order.placed', 'order.delivered'])->log();
    }

    protected function scope(FeedBuilder $feed): void
    {
        $feed->context($this->customer);
    }
}
