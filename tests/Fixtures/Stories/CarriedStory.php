<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Illuminate\Http\Request;
use RuntimeException;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\User;

/** Actions that take the request, for carrying into queued jobs. */
class CarriedStory
{
    /** @var array<string, int> how often each action ran */
    public static array $runs = [];

    public function ship(Verb $verb, Request $request): Verb
    {
        self::$runs['ship'] = (self::$runs['ship'] ?? 0) + 1;

        return $verb->headline(':actor shipped :object')->actor(User::find($request->input('shipper')));
    }

    public function jam(Verb $verb, Request $request): Verb
    {
        self::$runs['jam'] = (self::$runs['jam'] ?? 0) + 1;

        if ($request->has('jam')) {
            throw new RuntimeException('the printer jammed');
        }

        return $verb->headline(':actor jammed :object');
    }

    public function peek(Verb $verb): Verb
    {
        return $verb->headline(':actor peeked at :object')->actor(request()->input('peeker'));
    }
}
