<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Stories\Story;

/**
 * A one-verb Story class that names neither its verb nor its type: the line
 * in routes/feed.php that binds it does, as a route does for an invokable
 * controller.
 */
class DeliveryWasDispatched extends Story
{
    public function headline(): string
    {
        return ':actor dispatched :object';
    }

    public function icon(): ?string
    {
        return 'truck';
    }
}
