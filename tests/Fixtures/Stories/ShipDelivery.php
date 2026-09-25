<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Stories\Verb;

/** An invokable story class: extends nothing, one verb in `__invoke`. */
class ShipDelivery
{
    public static int $runs = 0;

    public function __invoke(Verb $verb): Verb
    {
        self::$runs++;

        return $verb->headline(':actor shipped :object')->anonymousHeadline(':object was shipped')->icon('send');
    }
}
