<?php

use Storyfeed\Facades\Story;
use Storyfeed\Grouping\Group;

/*
 * The workbench's definitions file. DeliveryWasConfirmed says what a row of
 * deliveries reads as; these rows can gather anything confirmed, so their
 * headlines belong to the verb and name no type.
 */

Story::verb('confirm')->grouped(
    Group::byActors()->headline(':actors confirmed :count things for :target'),
    Group::byTargets()->headline(':actor confirmed things for :targets'),
);
