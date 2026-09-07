<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The reserved-key convention (docs/payload.md, "Reserved `$` keys inside
 * `data`"), stated normatively 2026-09-07:
 *
 *   A `$`-prefixed key in `data` is not the app's. Core strips the ones core
 *   owns and passes every other one through untouched.
 *
 * The second clause is the load-bearing one and these tests are its guard.
 * A well-meaning "strip every `$`-prefixed key" on the read path would pass
 * every FeedThread test and delete a paying customer's payload — a detail
 * carrying `$detail`/`$v`, a key another package reserved, a key the app
 * chose for itself. Core owns `$thread` and `$change`.
 */

function recordWithReservedKeys(array $data, ?FeedThread $thread = null, string $tracking = 'TN-R'): Activity
{
    $pending = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => $tracking]))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->data($data);

    if ($thread !== null) {
        $pending->thread($thread);
    }

    return $pending->publish();
}

it('passes an unknown $-prefixed key through to node.data untouched', function () {
    recordWithReservedKeys([
        'ip' => '1.2.3.4',
        '$acme' => ['tenant' => 42, 'tags' => ['a', 'b']],
    ]);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    // Same keys, same order, same values: the app's map, exactly as recorded.
    expect($node['data'])->toBe([
        'ip' => '1.2.3.4',
        '$acme' => ['tenant' => 42, 'tags' => ['a', 'b']],
    ]);
});

it('strips only the key core owns when both are present', function () {
    recordWithReservedKeys([
        '$acme' => 'theirs',
        'note' => 'mine',
    ], FeedThread::make(text: 'Shipped.', replies: 1));

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['data'])->toBe(['$acme' => 'theirs', 'note' => 'mine'])
        ->and($node['data'])->not->toHaveKey(FeedThread::KEY)
        ->and($node['thread']['text'])->toBe('Shipped.');
});

it('carries a detail — $detail and $v inside the app\'s own key — to the renderer intact', function () {
    // The shape a `Contracts\FeedDetail` writes. Core does not own the key,
    // cannot find it without walking the app's map, and must not normalise it:
    // `$v` travels, and the renderer upgrades. See docs/payload.md.
    $detail = ['$detail' => 'change', '$v' => 2, 'field' => 'status', 'from' => 'draft', 'to' => 'sent'];

    recordWithReservedKeys(['change' => $detail], FeedThread::make(text: 'Sent.'));

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['data'])->toBe(['change' => $detail])
        ->and($node['data']['change']['$v'])->toBe(2);
});

it('passes an unknown $-key through on a row written directly to the column', function () {
    // A LITERAL column value, not a recording call — the key was put there by
    // something this package never saw (another package, a migration, a
    // healer), and the read path has no more right to it than to `ip`.
    $activity = recordWithReservedKeys(['placeholder' => true], null, 'TN-COL');
    $activity->forceFill(['data' => [
        '$vendor' => ['v' => 1],
        FeedThread::KEY => ['text' => 'Hi', 'by' => null, 'kind' => null, 'replies' => null, 'truncated' => false],
        'ip' => '1.2.3.4',
    ]])->save();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['data'])->toBe(['$vendor' => ['v' => 1], 'ip' => '1.2.3.4'])
        ->and($node['thread']['text'])->toBe('Hi');

    // And nothing was rewritten on the way past.
    expect($activity->fresh()->data['$vendor'])->toBe(['v' => 1]);
});
