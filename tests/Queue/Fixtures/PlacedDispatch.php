<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;

/** A queued message class that says where it goes, as a job class does. */
class PlacedDispatch extends Story implements ShouldQueue
{
    use Queueable;

    public string|array|null $objectType = Delivery::class;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Delivery $delivery)
    {
        $this->onQueue('stories');
    }

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery);
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }
}
