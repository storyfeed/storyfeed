<?php

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\PendingActivity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/**
 * A domain event that publishes to the feed.
 *
 * Note what is visible AT THE RECORDING SITE: the story, and every role. That
 * is the argument against the attribute form — `#[RecordsStory(...)]` would
 * name the story but leave the roles to be inferred. The message class
 * answers the same method, so the event hands it the roles and returns its
 * activity.
 */
class DeliveryConfirmed implements PublishesToFeed
{
    use Dispatchable;

    public function __construct(
        public Delivery $delivery,
        public User $user,
        public ?Customer $customer = null,
    ) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return (new DeliveryWasConfirmed($this->delivery, $this->user, $this->customer))->toFeedActivity();
    }
}
