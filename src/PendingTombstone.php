<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;

/**
 * What a Feedable asks of the tombstone it will leave, configured in its
 * `toFeed()` entity:
 *
 *     $this->feedEntity()
 *         ->label("Order #{$this->reference}")
 *         ->tombstone(fn (PendingTombstone $tombstone) => $tombstone->keepLabel());
 *
 * With no call, a deleted model's tombstone follows Activity Streams 2.0: its
 * properties are stripped, so it reads "a removed order", and every story
 * that named it stays.
 *
 * THIS IS THE ENTITY'S TOMBSTONE, not its activities'. What an activity
 * becomes once a role it is about is gone belongs to its verb:
 * `->missingHeadline()` rewrites it and `->forgetWhenMissing()` deletes it.
 * `forgetActivities()` lived here until 2026-09-23, and moved because it was
 * never about one entity: a bulk delete and the trickle have no entity to
 * ask.
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

    /** @internal */
    public function keepsLabel(): bool
    {
        return $this->keepLabel;
    }
}
