<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Stories\Verb;

/** A resource Story class whose verbs each group per their own period. */
class PeriodicDeliveryStory
{
    public function scan(Verb $verb): Verb
    {
        return $verb->headline(':actor scanned :object')->groupedHourly();
    }

    public function invoice(): array
    {
        return ['headline' => ':actor invoiced :object', 'groupedPer' => 'month'];
    }

    public function note(): string
    {
        return ':actor noted :object';
    }
}
