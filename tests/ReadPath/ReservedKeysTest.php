<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function recordWithReservedKeys(array $data, string $tracking = 'TN-R'): Activity
{
    $pending = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => $tracking]))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->data($data);

    return $pending->publish();
}

it('passes an unknown $-prefixed key through to node.data untouched', function () {
    recordWithReservedKeys([
        'ip' => '1.2.3.4',
        '$acme' => ['tenant' => 42, 'tags' => ['a', 'b']],
    ]);

    $node = Storyfeed::feed()->get()->toArray()[0];

    // Same keys and strict values; native JSON storage may reorder object keys.
    expect(jsonObjectKeys($node['data']))->toBe(jsonObjectKeys([
        'ip' => '1.2.3.4',
        '$acme' => ['tenant' => 42, 'tags' => ['a', 'b']],
    ]));
});

it('carries a detail — $body and $v inside the app\'s own key — to the renderer intact', function () {
    // The shape a `Contracts\FeedBody` writes. Core does not own the key,
    // cannot find it without walking the app's map, and must not normalise it:
    // `$v` travels, and the renderer upgrades. See docs/payload.md.
    $body = ['$body' => 'change', '$v' => 2, 'field' => 'status', 'from' => 'draft', 'to' => 'sent'];

    recordWithReservedKeys(['change' => $body]);

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect(jsonObjectKeys($node['data']))->toBe(jsonObjectKeys(['change' => $body]))
        ->and($node['data']['change']['$v'])->toBe(2);
});

it('passes an unknown $-key through on a row written directly to the column', function () {
    // A LITERAL column value, not a recording call — the key was put there by
    // something this package never saw (another package, a migration, a
    // healer), and the read path has no more right to it than to `ip`.
    $activity = recordWithReservedKeys(['placeholder' => true], 'TN-COL');
    $activity->forceFill(['data' => [
        '$vendor' => ['v' => 1],
        '$thread' => ['text' => 'Hi', 'by' => null, 'kind' => null, 'replies' => null, 'truncated' => false],
        'ip' => '1.2.3.4',
    ]])->save();

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['data'])->toBe($activity->fresh()->data)
        ->and($node)->not->toHaveKey('thread');

    // And nothing was rewritten on the way past.
    expect($activity->fresh()->data['$vendor'])->toBe(['v' => 1]);
});

it('passes a stored $change through to node.data as written', function () {
    // Core owned `$change` until the change body left core. Rows recorded
    // before then keep it, and the app that wrote it now reads it from data.
    $change = ['$v' => 1, 'changes' => [['label' => 'Status', 'before' => 'Draft', 'after' => 'Ready']]];
    $activity = recordWithReservedKeys(['placeholder' => true], 'TN-CHG');
    $activity->forceFill(['data' => ['$change' => $change, 'ip' => '1.2.3.4']])->save();

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect(jsonObjectKeys($node['data']))->toBe(jsonObjectKeys(['$change' => $change, 'ip' => '1.2.3.4']))
        ->and($node)->not->toHaveKey('change');
});

it('preserves historical thread data without upgrading or emitting replies', function (mixed $thread) {
    $data = ['$thread' => $thread, 'thread' => $thread, '$change' => ['$v' => 1], 'source' => 'archive'];
    $activity = recordWithReservedKeys(['placeholder' => true]);
    $activity->forceFill(['data' => $data])->save();

    $node = Storyfeed::feed()->get()->toArray()[0];
    $document = app(ActivitySerializer::class)->activity($activity->fresh());

    expect(jsonObjectKeys($node['data']))->toBe(jsonObjectKeys($data))
        ->and($node)->not->toHaveKey('thread')
        ->and($document)->not->toHaveKey('replies')
        ->and(jsonObjectKeys($activity->fresh()->data))->toBe(jsonObjectKeys($data));
})->with([
    'unversioned' => [['text' => 'Original', 'replies' => 3]],
    'versioned' => [['$v' => 1, 'text' => 'Original', 'replies' => 3]],
    'future' => [['$v' => 99, 'text' => 'Original', 'extra' => ['keep' => true]]],
    'malformed' => ['untouched'],
    'null' => [null],
]);
