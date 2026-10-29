<?php

namespace App\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityFeedQuery
{
    public function get(): Collection
    {
        return DB::table('activity_log')->latest('id')->get();
    }
}
