<?php

namespace Workbench\App\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\Story;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/**
 * The canonical Story: everything about one activity type in one file.
 *
 * Note both `$objectType` and `$verb` are declared. Nothing is inferred from
 * the class name at runtime — `make:story` parses `Delivery`+`WasConfirmed`
 * and writes these two lines, so a wrong guess appears in the diff instead of
 * self-registering a wrong verb past strict mode.
 */
class DeliveryWasConfirmed extends Story
{
    public string|array|null $objectType = Delivery::class;

    public string|FeedVerb|BackedEnum|null $verb = ActivityVerb::Confirm;

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
            Group::byActors()->headline(':actors confirmed :count deliveries for :target'),
            Group::byTargets()->headline(':actor confirmed deliveries for :targets'),
            Group::repeat()->headline(':actor confirmed :count deliveries'),
        ];
    }
}
