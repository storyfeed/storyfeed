<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;

/**
 * What a Feedable asks of the tombstone it will leave, configured in its
 * `toFeed()` entity:
 *
 *     $this->feedEntity()
 *         ->label("Order #{$this->reference}")
 *         ->tombstone(fn (PendingTombstone $tombstone) => $tombstone->keepLabel()->forgetActivities());
 *
 * With no call, a deleted model's tombstone follows Activity Streams 2.0: its
 * properties are stripped, so it reads "a removed order", and every story
 * that named it stays.
 *
 * PENDING, like PendingActivity and Laravel's PendingDispatch: the tombstone
 * doesn't exist yet when this is configured; the delete makes it. Not
 * `FeedTombstone`, which is the stored model, and not a "policy", which in
 * Laravel means authorization.
 */
final class PendingTombstone
{
    use Conditionable;

    private bool $keepLabel = false;

    private bool $forgetActivities = false;

    /**
     * Keep the model's label on its tombstone, so its stories go on naming
     * it ("Order #1042") instead of "a removed order". The name then
     * outlives the model, which is the privacy AS2's Tombstone gives up.
     */
    public function keepLabel(bool $keep = true): self
    {
        $this->keepLabel = $keep;

        return $this;
    }

    /**
     * On a HARD delete, delete the activities this model made redundant: the
     * ones where it fills a role the verb is about (the object, by default;
     * see `->missing()`). Its other stories stay, told about a tombstone.
     *
     * Never on a soft delete, so a restore can always undo one. A soft-
     * deleted model that is later force-deleted forgets them then.
     */
    public function forgetActivities(bool $forget = true): self
    {
        $this->forgetActivities = $forget;

        return $this;
    }

    /** @internal */
    public function keepsLabel(): bool
    {
        return $this->keepLabel;
    }

    /** @internal */
    public function forgetsActivities(): bool
    {
        return $this->forgetActivities;
    }
}
