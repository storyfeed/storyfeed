<?php

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\PendingActivity;
use Storyfeed\StoryfeedManager;

if (! function_exists('storyfeed')) {
    /**
     * Storyfeed's manager, or a pending activity when a verb is given.
     *
     *   storyfeed()->record(ActivityVerb::Comment, object: $comment);
     *   storyfeed()->feed()->context($project)->get();
     *   storyfeed(ActivityVerb::Confirm, $delivery)->actor($user)->publish();
     *
     * @return ($verb is null ? StoryfeedManager : PendingActivity)
     */
    function storyfeed(string|FeedVerb|BackedEnum|null $verb = null, ?Model $object = null): StoryfeedManager|PendingActivity
    {
        $manager = app(StoryfeedManager::class);

        return $verb === null ? $manager : $manager->activity($verb, $object);
    }
}
