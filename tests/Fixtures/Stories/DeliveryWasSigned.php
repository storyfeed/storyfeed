<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;

/**
 * A message class that gives a headline to a grouping whose rows can
 * hold more than deliveries, which a Story class may not do.
 */
class DeliveryWasSigned extends Story
{
    public string|array|null $objectType = Delivery::class;

    public string|FeedVerb|BackedEnum|null $verb = 'sign';

    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery);
    }

    public function headline(): string
    {
        return ':actor signed :object';
    }

    public function groups(): array
    {
        return [Group::byTargets()->headline(':actor signed deliveries for :targets')];
    }
}
