<?php

namespace Storyfeed\Tests\Fixtures\Unaliased;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/** A Feedable with no alias and no aliased parent to borrow one from. */
class Stray extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'customers';
}
