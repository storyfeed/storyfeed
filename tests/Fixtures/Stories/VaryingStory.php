<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Illuminate\Http\Request;
use Storyfeed\Stories\Verb;

/** Breaks the rule: its headline depends on the request. */
class VaryingStory
{
    public function place(Verb $verb, Request $request): Verb
    {
        return $verb->headline($request->has('rush') ? ':actor rushed :object' : ':actor placed :object');
    }
}
