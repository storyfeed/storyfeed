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
    function storyfeed(string|FeedVerb|BackedEnum|null $verb = null, Model|string|null $object = null): StoryfeedManager|PendingActivity
    {
        $manager = app(StoryfeedManager::class);

        return $verb === null ? $manager : $manager->activity($verb, $object);
    }
}

if (! function_exists('story')) {
    /**
     * A pending activity for a named story: the feed's `route()`. The name
     * is the handle call sites use, as a route's name is, and the key
     * `type.verb` it names plays the URI's part.
     *
     *     story('order.ship', $order)->by($user)->publish();
     *
     * Takes a name only. A name nothing defined throws StoryNotFound, always,
     * as `route()` throws RouteNotFoundException; an object of another type
     * than the name's throws StoryObjectMismatch. `Story::resource()` names
     * its verbs `{type}.{verb}`; anything else is named with `->name()`.
     * An unnamed verb is recorded by its verb:
     * `Storyfeed::activity('ship', $order)` or `Act::Ship->of($order)`.
     *
     * A name bound to a message class throws: the class is constructed and
     * published, `Storyfeed::publish(new OrderShipped($order))`, so its
     * toFeedActivity() always runs.
     */
    function story(string|BackedEnum $name, Model|string|null $object = null): PendingActivity
    {
        return app(StoryfeedManager::class)->route($name, $object);
    }
}
