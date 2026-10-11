<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/** Feedable through the trait, which refreshes its snapshot on save. */
class StoragelessProject extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'storageless_projects';

    protected $guarded = [];

    public $timestamps = false;
}
