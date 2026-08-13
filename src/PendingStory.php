<?php

namespace Storyfeed;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\UnknownStory;

/**
 * The activity a `PublishesToFeed` implementor describes — a PendingActivity
 * that can also name a Story class.
 *
 * Subclassing PendingActivity rather than inventing a DTO is deliberate:
 * "a described-but-unpublished activity" already exists, and it already carries
 * the whole fluent surface (actor/object/objects/target/in/to/for/from/context/
 * data/publishedAt/replace/when). A hand-forwarded DTO would be a third surface
 * to keep in parity, and the trait that forwards this builder for verb enums
 * already pays that tax with a dozen methods and a reflection test to prove it
 * has not drifted.
 *
 * Two ways to name what is published, matching Jasper's ad-hoc-disks framing:
 *
 *   // point at a Story class — verb, AS2 type and grammar come from it
 *   PendingStory::of(DeliveryWasConfirmed::class)->object($delivery)->actor($user)
 *
 *   // declare inline, no class required
 *   PendingStory::inline(ActivityVerb::Confirm)->object($delivery)->actor($user)
 *
 * Ad-hoc means "no Story CLASS needed", not "grammar inline". Grammar resolves
 * at read time from the compiled registries, and an inline headline would live
 * on an instance of an event that takes constructor arguments — so it could not
 * be compiled at boot without instantiating the event, and honouring it would
 * mean storing templates per row, duplicating the architecture and punching
 * through the frozen payload's resolution path. `Storage::build()` is the same
 * bargain: an unnamed disk, still using the same driver machinery.
 */
class PendingStory extends PendingActivity
{
    /**
     * Publish the activity a registered Story describes.
     *
     * Throws for an unregistered Story rather than publishing a verbless
     * activity — a typo'd or unregistered class must not degrade into a row
     * nobody authored a headline for.
     *
     * Typed as a plain string rather than `class-string<Story>` on purpose:
     * the guard below exists BECAUSE callers pass whatever they have, and
     * narrowing the annotation would tell the analyser the check is unreachable
     * while leaving the runtime exactly as exposed.
     */
    public static function of(string|Story $story): static
    {
        $class = is_string($story) ? $story : $story::class;

        if (! is_a($class, Story::class, true)) {
            throw UnknownStory::notAStory($class);
        }

        $registered = array_filter(
            app(StoryfeedManager::class)->registeredStories(),
            fn (mixed $entry) => $entry === $class || $entry instanceof $class,
        );

        if ($registered === []) {
            throw UnknownStory::unregistered($class);
        }

        return static::make($class::verb());
    }

    /**
     * Declare the activity inline, with no Story class.
     *
     * A thin alias of make(); it exists so the call site READS as deliberate.
     * `inline()` says "ad-hoc, on purpose", which is what makes the ad-hoc cases
     * greppable when someone later asks which events bypass the Story layer.
     */
    public static function inline(string|FeedVerb|BackedEnum $verb): static
    {
        return static::make($verb);
    }
}
