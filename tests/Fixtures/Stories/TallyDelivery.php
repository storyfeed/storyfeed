<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Stories\Verb;

/** An invokable story class whose verb groups per week. */
class TallyDelivery
{
    public function __invoke(Verb $verb): Verb
    {
        return $verb->headline(':actor tallied :object')->groupedWeekly();
    }
}
