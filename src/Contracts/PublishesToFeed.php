<?php

namespace Storyfeed\Contracts;

use Storyfeed\PendingActivity;

/**
 * Something that publishes an activity to the feed when it happens.
 *
 * Shaped after `Feedable::toFeed()` — a model describes how it APPEARS in the
 * feed; this describes what it PUTS there:
 *
 *   class DeliveryConfirmed implements PublishesToFeed
 *   {
 *       public function __construct(public Delivery $delivery, public User $user) {}
 *
 *       public function toFeedActivity(): ?PendingActivity
 *       {
 *           return Storyfeed::activity()
 *               ->by($this->user)
 *               ->action('confirm', $this->delivery);
 *       }
 *
 *   }
 *
 * The return type is the plain builder, so the event writes exactly the line a
 * listener would, minus `->publish()`. `SomeStory::activity($object)`,
 * `PendingActivity::of(SomeStory::class)` and `PendingActivity::inline($verb)`
 * all build one; nothing downstream needs anything narrower — `publishFor()`
 * only publishes.
 *
 * Wiring is ONE line in this package's provider:
 * `Event::listen(PublishesToFeed::class, PublishFeedActivity::class)`. Laravel's
 * dispatcher walks `class_implements()` for object events, so an
 * interface-registered listener receives every implementor and costs nothing for
 * events that do not implement it — unlike a wildcard listener, which would run
 * on every Eloquent event the app dispatches.
 *
 * WHY AN INTERFACE, NOT AN ATTRIBUTE. `#[RecordsStory(SomeStory::class)]` was
 * the original sketch and is rejected: it names a story but not the ROLES, so
 * the roles must be inferred, which hides the recording site — the exact class
 * of magic this package deleted `__call` to escape. A required method forces the
 * recording site to be written out, autocompletes, and fails at class-load if
 * unimplemented. It also shows up in `php artisan event:list`.
 *
 * WHY `toFeedActivity()` AND NOT `toFeed()`. The shape is deliberately the same but
 * the name is not: `Feedable::toFeed()` returns a `FeedEntity`, so sharing the
 * name would make a class that is BOTH feedable and publishing a fatal error at
 * class-load. A Feedable model that also publishes on some lifecycle event is a
 * perfectly reasonable thing to want, and one letter of naming should not
 * foreclose it.
 *
 * SCOPE. Domain events today. Nothing here mentions "event" or takes one as an
 * argument, so a Job, Mailable or Notification can adopt it later with zero
 * contract change — only a new place to hook the same seam
 * (`StoryfeedManager::publishFor()`).
 */
interface PublishesToFeed
{
    /**
     * The activity to publish, or null to deliberately publish nothing.
     *
     * Returning null is the supported way to say "this time, nothing happened
     * worth telling" — a guard clause, not an error.
     */
    public function toFeedActivity(): ?PendingActivity;
}
