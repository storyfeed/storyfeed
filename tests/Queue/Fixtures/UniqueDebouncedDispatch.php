<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUnique;

/** Debounced and unique: a job refuses this, and so does a queued story. */
class UniqueDebouncedDispatch extends DebouncedDispatch implements ShouldBeUnique {}
