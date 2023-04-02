<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;

class FeedGroup extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function activity()
    {
        return $this->belongsTo(FeedActivity::class);
    }

    public static function remove($activityId, $context = null)
    {
        return static::query()
            ->where('feed_activity_id', $activityId)
            ->where('context', $context)
            ->delete();
    }

    public static function assign($activityId, $signature, $context = null)
    {
        return static::query()
            ->updateOrCreate([
                'feed_activity_id' => $activityId,
                'context' => $context,
            ], [
                'signature' => $signature,
            ]);
    }
}
