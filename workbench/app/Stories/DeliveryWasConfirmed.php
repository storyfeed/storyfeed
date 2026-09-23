<?php

namespace Workbench\App\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Story;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/**
 * The canonical Story: everything about one activity type in one file.
 *
 * Note both `$objectType` and `$verb` are declared, so it registers without a
 * binding line in routes/feed.php. Nothing is inferred from the class name at
 * runtime — `make:story` parses `Delivery`+`WasConfirmed` and prints the line
 * that binds it, so a wrong guess is seen instead of self-registering a wrong
 * verb past strict mode.
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
