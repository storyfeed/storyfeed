<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;

class FeedEntity extends Model
{
    protected $guarded = [];

    public function activity()
    {
        return $this->belongsTo(FeedActivity::class);
    }
}
