<?php

namespace Workbench\App\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The canonical Story: one activity type as a message, constructed with its
 * data and published, `Storyfeed::publish(new DeliveryWasConfirmed($delivery,
 * $user))`, and bound to its verb in routes/feed.php.
 *
 * `$verb` is declared because the enum case carries the AS2.0 type, and
 * `$objectType` so a line outside `Story::for()` can bind it. Nothing is
 * inferred from the class name.
 *
 * Its group headlines are about deliveries, as everything in the class is:
 * a row of one person's repeated confirms holds only deliveries. A grouping
 * that can put other things in the same row (everything confirmed by these
 * people, or for these customers) belongs to the verb, so its headlines live
 * in routes/feed.php, worded so they name no type.
 */
class DeliveryWasConfirmed extends Story
{
    public string|array|null $objectType = Delivery::class;

    public string|FeedVerb|BackedEnum|null $verb = ActivityVerb::Confirm;

    public function __construct(
        public Delivery $delivery,
        public User $user,
        public ?Customer $customer = null,
    ) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery)->by($this->user)->for($this->customer);
    }

    public function headline(): string
    {
        return ':actor confirmed :object for :target';
    }

    public function icon(): ?string
    {
        return 'bi-truck';
    }

    public function groups(): array
    {
        return [
            Group::repeat()->headline(':actor confirmed :count deliveries'),
        ];
    }
}
