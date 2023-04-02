<?php

namespace Storyfeed;

use Storyfeed\Support\ActivityBuilder;

class Storyfeed
{
    public function activity(...$args): ActivityBuilder
    {
        return ActivityBuilder::make(...$args);
    }
}
