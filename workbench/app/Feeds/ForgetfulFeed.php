<?php

namespace Workbench\App\Feeds;

use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;

/**
 * Hand-written, and wrong in the one way that matters: it takes a subject and
 * never binds it. The backstop for the guarantee living in generated code.
 */
class ForgetfulFeed extends Feed
{
    public function __construct(protected Customer $customer) {}

    public function define(FeedBuilder $feed): void
    {
        $feed->only(['order.placed']);
    }
}
