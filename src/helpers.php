<?php

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\UnknownStory;
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
    function storyfeed(string|FeedVerb|BackedEnum|null $verb = null, Model|string|null $object = null): StoryfeedManager|PendingActivity
    {
        $manager = app(StoryfeedManager::class);

        return $verb === null ? $manager : $manager->activity($verb, $object);
    }
}

if (! function_exists('story')) {
    /**
     * A pending activity for a verb. The verb is the public handle, as a
     * route's name is, so this is the feed's `route()`: call sites name the
     * verb and never the Story class that declares it.
     *
     *   story('ship', $order)->by($user)->publish();
     *   story(Act::Ship)->by($user)->objects($orders)->publish();
     *
     * A verb bound to a message class throws: the class is constructed and
     * published, `Storyfeed::publish(new OrderShipped($order))`, so its
     * toFeedActivity() always runs.
     */
    function story(string|FeedVerb|BackedEnum $verb, Model|string|null $object = null): PendingActivity
    {
        $storyfeed = app(StoryfeedManager::class);
        $type = $object instanceof Model ? $object->getMorphClass() : null;

        if (($message = $storyfeed->messageFor($verb, $type)) !== null) {
            throw UnknownStory::boundToMessage($verb, $message);
        }

        return $storyfeed->activity($verb, $object);
    }
}
