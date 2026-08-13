<?php

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\PendingStory;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/**
 * A domain event that publishes to the feed.
 *
 * Note what is visible AT THE RECORDING SITE: the story, and every role. That
 * is the argument against the attribute form — `#[RecordsStory(...)]` would
 * name the story but leave the roles to be inferred.
 */
class DeliveryConfirmed implements PublishesToFeed
{
    use Dispatchable;

    public function __construct(
        public Delivery $delivery,
        public User $user,
        public ?Customer $customer = null,
    ) {}

    public function toFeedStory(): ?PendingStory
    {
        return PendingStory::of(DeliveryWasConfirmed::class)
            ->object($this->delivery)
            ->actor($this->user)
            ->for($this->customer);
    }
}
