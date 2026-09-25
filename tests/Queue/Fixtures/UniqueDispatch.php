<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;

/** While one publish per delivery is pending, another is dropped. */
class UniqueDispatch extends Story implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public Delivery $delivery) {}

    public function uniqueId(): string
    {
        return (string) $this->delivery->getKey();
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
