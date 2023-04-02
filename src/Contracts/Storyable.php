<?php

namespace Storyfeed\Contracts;

use Storyfeed\Story;

interface Storyable
{
    public function toStory(): Story;
}
