<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Illuminate\Http\Request;
use Storyfeed\Stories\Verb;

/** An action that takes the request, so it chooses the actor at each publish. */
class RefundStory
{
    /** @var list<string|null> the provider each run saw */
    public static array $seen = [];

    public function refund(Verb $verb, Request $request): Verb
    {
        self::$seen[] = $request->input('provider');

        return $verb->headline(':actor refunded :object')->actor($request->input('provider'));
    }

    public function sync(Verb $verb): Verb
    {
        return $verb->headline(':actor synced :object')->actor('Stripe');
    }
}
