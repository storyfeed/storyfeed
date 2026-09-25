<?php

namespace Storyfeed\Tests\Static\Fixtures;

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;

function namedStoryCalls(Delivery $delivery, StoryfeedManager $manager, string $dynamic): void
{
    story('delivery.ship', $delivery);
    story('delivery.shp', $delivery);

    Storyfeed::route('delivery.ship', $delivery);
    Storyfeed::route('order.confirm');

    Story::has('delivery.ship');
    Story::has('delivery.lost');

    $manager->route('delivery.sent');

    // Built at runtime: not known here, so not checked.
    story($dynamic);
    story("delivery.{$dynamic}");

    // A value PHPStan knows is checked, as a literal is.
    $gone = 'delivery.gone';
    story($gone);
}
