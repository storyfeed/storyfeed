<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;

/**
 * A message class that names neither its verb nor its type: the line in
 * routes/feed.php that binds it does, as a route does for an invokable
 * controller. Its constructor requires a delivery, so compiling it proves
 * the constructor is never called at boot.
 */
class DeliveryWasDispatched extends Story
{
    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery);
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }

    public function icon(): ?string
    {
        return 'truck';
    }
}
