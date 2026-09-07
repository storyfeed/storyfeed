<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Durable package state: single values that must outlive requests and
 * survive deploys — the sync token today, future ops state as needed.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property Carbon $created_at
 */
class Meta extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('storyfeed.tables.meta', 'feed_meta');
    }
}
