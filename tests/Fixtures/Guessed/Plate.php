<?php

namespace Storyfeed\Tests\Fixtures\Guessed;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/**
 * A Feedable with no feed code at all: its label is guessed. The doctor's
 * `labels` check scans this directory for it.
 */
class Plate extends Model implements Feedable
{
    use InteractsWithFeed;
}
