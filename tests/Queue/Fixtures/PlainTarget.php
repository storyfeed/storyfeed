<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A supported non-Feedable participant has no shared snapshot lock. */
class PlainTarget extends Model
{
    protected $table = 'customers';

    protected $guarded = [];

    public function getMorphClass(): string
    {
        return 'queue-target';
    }
}
