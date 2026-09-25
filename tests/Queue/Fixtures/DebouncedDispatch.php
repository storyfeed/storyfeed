<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DebounceFor;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;

/** An unsupported debounce declaration, rejected at the call site. Laravel 13 only. */
#[DebounceFor(30)]
class DebouncedDispatch extends Story implements ShouldQueue
{
    use Queueable;

    public function __construct(public Delivery $delivery, public string $status = '') {}

    public function debounceId(): string
    {
        return (string) $this->delivery->getKey();
    }

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery)->data(['status' => $this->status]);
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }
}
