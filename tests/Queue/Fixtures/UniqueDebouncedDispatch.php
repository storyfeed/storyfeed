<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUnique;

/** An inherited debounce attribute is rejected, including on a unique story. */
class UniqueDebouncedDispatch extends DebouncedDispatch implements ShouldBeUnique {}
