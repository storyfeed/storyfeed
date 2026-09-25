<?php

use Storyfeed\Facades\Story;
use Storyfeed\Grouping\Group;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The workbench's definitions file. DeliveryWasConfirmed is bound to its
 * verb, and says what a row of deliveries reads as; the rows below can
 * gather anything confirmed, so their headlines belong to the verb and name
 * no type.
 */

Story::for(Delivery::class)->verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

Story::verb('confirm')->grouped(
    Group::byActors()->headline(':actors confirmed :count things for :target'),
    Group::byTargets()->headline(':actor confirmed things for :targets'),
);
