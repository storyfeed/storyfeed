<?php

namespace Storyfeed\Contracts;

use Storyfeed\Data\FeedObject;

interface PublishesToFeed
{
    public function toFeed(): FeedObject;
}
