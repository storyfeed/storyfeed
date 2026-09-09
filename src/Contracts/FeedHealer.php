<?php

namespace Storyfeed\Contracts;

use Storyfeed\Healing\StoryRetirement;

/** App-owned policy for permanent source absence; never infers missing stories. */
interface FeedHealer
{
    /** The name accepted by storyfeed:heal --only. */
    public function key(): string;

    /**
     * Describe retirements without writing anything, including during iteration.
     *
     * @return iterable<StoryRetirement>
     */
    public function candidates(): iterable;
}
