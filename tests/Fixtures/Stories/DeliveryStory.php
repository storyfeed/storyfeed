<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Stories\Verb;

/** A resource Story class: extends nothing, every public method a verb. */
class DeliveryStory
{
    public static int $runs = 0;

    public function create(Verb $verb): Verb
    {
        self::$runs++;

        return $verb->headline(':actor booked :object')->icon('truck');
    }

    public function confirmPayment(): string
    {
        return ':actor confirmed payment for :object';
    }

    public function ship(): array
    {
        return ['headline' => ':actor shipped :object', 'icon' => 'send', 'missingHeadline' => ':actor shipped a delivery since removed'];
    }

    // Verbs a base class would have reserved.
    public function publish(Verb $verb): Verb
    {
        return $verb->headline(':actor published :object');
    }

    public function record(): string
    {
        return ':actor recorded :object';
    }

    public static function helperOnTheClass(): int
    {
        return 1;
    }

    protected function label(): string
    {
        return 'a helper, never a verb';
    }
}
