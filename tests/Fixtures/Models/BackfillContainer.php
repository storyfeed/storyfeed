<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Storyfeed\FeedEntity;

class BackfillContainer extends NestedContainer
{
    public static bool $declaresParent = false;

    public function toFeed(): FeedEntity
    {
        return self::$declaresParent ? parent::toFeed() : FeedEntity::make($this->name);
    }
}
