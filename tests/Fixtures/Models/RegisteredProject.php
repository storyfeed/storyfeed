<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Feedable through Storyfeed::feedable(), with no feed code on the model. */
class RegisteredProject extends Model
{
    protected $table = 'storageless_projects';

    protected $guarded = [];

    public $timestamps = false;
}
