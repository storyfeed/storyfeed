<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Verb;

/** An invokable story class bound for every type, whose group headline names none. */
class ConfirmAnything
{
    public function __invoke(Verb $verb): Verb
    {
        return $verb->headline(':actor confirmed :object')
            ->grouped(Group::byActors()->headline(':actors confirmed :count things'));
    }
}
