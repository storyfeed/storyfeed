<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Storyfeed\Tests\Fixtures\Middleware\Trace;
use Workbench\App\Models\Delivery;

/** A message class with its own middleware, as a job has. */
class DeliveryWasSorted extends Story
{
    public string|array|null $objectType = Delivery::class;

    public string|FeedVerb|BackedEnum|null $verb = 'sort';

    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery)->by('Courier');
    }

    public function headline(): string
    {
        return ':actor sorted :object';
    }

    public function middleware(): array
    {
        return [Trace::class.':class', 'batch:5 minutes'];
    }
}
