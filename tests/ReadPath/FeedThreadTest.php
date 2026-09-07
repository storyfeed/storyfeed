<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * FeedThread (2026-09-06): the utterance a row is about, and the size of the
 * conversation around it, in ONE object.
 *
 * The bug it exists for: a row said "· 1 reply" in its headline and quoted,
 * underneath, the thread's OPENING QUESTION rather than the reply. The count
 * came from a grammar closure, the quote from a renderer detail — two homes,
 * no shared truth. So the property under test throughout is that both facts
 * ride the same node key, written once at record time.
 */

function threadActivity(FeedThread $thread, string $tracking = 'TN-T'): Activity
{
    return Storyfeed::activity('confirm', Delivery::create(['tracking_number' => $tracking]))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->thread($thread)
        ->publish();
}

it('carries the utterance and the count on one node key', function () {
    threadActivity(FeedThread::make(
        text: 'Can we push the delivery to Thursday?',
        by: 'Nayani',
        kind: 'asked',
        replies: 3,
        truncated: true,
    ));

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBe([
        'text' => 'Can we push the delivery to Thursday?',
        'by' => 'Nayani',
        'kind' => 'asked',
        'replies' => 3,
        'truncated' => true,
    ]);
});

it('is null on every activity that has not opted in', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-0']))->publish();

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['thread'])->toBeNull();
});

it('leaves the app\'s own data exactly as it was recorded', function () {
    // The reserved key lives INSIDE the data column, so the one thing that
    // must not happen is the app seeing it in `data`.
    threadActivity(FeedThread::make(text: 'Shipped.'));

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['data'])->toBe([])
        ->and($node['thread']['text'])->toBe('Shipped.');
});

it('survives data() being called after thread(), and before it', function () {
    $after = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-A']))
        ->thread(FeedThread::make(text: 'One'))
        ->data(['ip' => '1.2.3.4'])
        ->publish();

    $before = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-B']))
        ->data(['ip' => '1.2.3.4'])
        ->thread(FeedThread::make(text: 'One'))
        ->publish();

    expect($after->data)->toBe($before->data);

    $nodes = collect(Storyfeed::feed()->get()->toArray()['items'])
        ->flatMap(fn (array $node) => $node['kind'] === 'group' ? $node['children'] : [$node]);

    foreach ($nodes as $node) {
        expect($node['data'])->toBe(['ip' => '1.2.3.4'])
            ->and($node['thread']['text'])->toBe('One');
    }
});

it('takes named arguments and fluent setters to the same place', function () {
    $named = FeedThread::make(text: 'Hi', by: 'Sam', kind: 'replied', replies: 2, truncated: true);

    $fluent = FeedThread::make('Hi')->by('Sam')->kind('replied')->replies(2)->truncated();

    expect($fluent->toArray())->toBe($named->toArray());
});

it('is ACTIVITY-scoped: two rows about one conversation show different utterances', function () {
    // The load-bearing decision. An entity-scoped object would carry ONE
    // utterance for every row about that thread, and this test is what it
    // could not pass.
    $ticket = Delivery::create(['tracking_number' => 'TN-SHARED']);

    Storyfeed::activity('confirm', $ticket)
        ->thread(FeedThread::make(text: 'Can we push to Thursday?', kind: 'asked', replies: 0))
        ->publish();

    Storyfeed::activity('confirm', $ticket)
        ->thread(FeedThread::make(text: 'Thursday works.', kind: 'replied', replies: 1))
        ->publish();

    $items = Storyfeed::feed()->get()->toArray()['items'];
    $threads = collect($items)
        ->flatMap(fn (array $node) => $node['kind'] === 'group' ? $node['children'] : [$node])
        ->pluck('thread.text')
        ->all();

    expect($threads)->toContain('Can we push to Thursday?', 'Thursday works.');
});

it('reads a stray column value as absent rather than throwing', function () {
    // Total by contract: the row is in the database either way.
    expect(FeedThread::fromArray('not a thread'))->toBeNull()
        ->and(FeedThread::fromArray(null))->toBeNull();

    // A count nobody recorded stays null — never a fabricated 0, which
    // would print "0 replies" as a fact.
    $partial = FeedThread::fromArray(['text' => 'Hi', 'replies' => 'lots']);

    expect($partial->replies)->toBeNull()
        ->and($partial->text)->toBe('Hi')
        ->and($partial->truncated)->toBeFalse();

    expect(FeedThread::fromArray([])->text)->toBe('');
});

it('serializes the count as an AS2 replies Collection and nothing else', function () {
    $activity = threadActivity(FeedThread::make(
        text: 'Can we push the delivery to Thursday?',
        by: 'Nayani',
        kind: 'asked',
        replies: 3,
    ));

    $document = app(ActivitySerializer::class)->activity($activity);

    expect($document['replies'])->toBe(['type' => 'Collection', 'totalItems' => 3]);

    // The utterance does NOT travel: `content` belongs to an object, not to
    // an activity's presentation of one. And no sf: term was minted.
    $json = json_encode($document);

    expect($json)->not->toContain('Can we push')
        ->and($json)->not->toContain('sf:thread')
        ->and($json)->not->toContain('$thread');
});

it('omits replies entirely when nobody counted', function () {
    $activity = threadActivity(FeedThread::make(text: 'Shipped.', kind: 'decided'));

    expect(app(ActivitySerializer::class)->activity($activity))->not->toHaveKey('replies');
});

/*
 * Versioning (2026-09-07). `Detail`'s rule 1, applied to the other value that
 * lives in the same `data` column: a row recorded today outlives the class
 * that recorded it. `FeedThread` shipped a day before this without a version,
 * so the rows that prove the rule already exist — and the answer to "which
 * version are they" has to be a definition, not a guess.
 */

it('writes the version into storage and never into the node', function () {
    // THE WHOLE DECISION IN ONE TEST. Storage is versioned; the payload is
    // not. Core upgrades on read, so a renderer is handed the current shape
    // by construction and `node.thread` keeps the five keys it has always had.
    $activity = threadActivity(FeedThread::make(text: 'Thursday works.', kind: 'replied', replies: 2));

    expect($activity->fresh()->data[FeedThread::KEY])->toBe([
        'text' => 'Thursday works.',
        'by' => null,
        'kind' => 'replied',
        'replies' => 2,
        'truncated' => false,
        '$v' => 1,
    ]);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBe([
        'text' => 'Thursday works.',
        'by' => null,
        'kind' => 'replied',
        'replies' => 2,
        'truncated' => false,
    ])->and($node['thread'])->not->toHaveKey('$v')
        ->and($node['data'])->toBe([]);
});

it('reads a row written before versioning exactly as it read yesterday', function () {
    // A LITERAL array, not FeedThread::make() — the point is a payload this
    // class never touched, of the shape that is in production right now.
    $legacy = ['text' => 'Can we push to Thursday?', 'by' => 'Nayani', 'kind' => 'asked', 'replies' => 3, 'truncated' => true];

    $activity = threadActivity(FeedThread::make(text: 'overwritten'), 'TN-LEGACY');
    $activity->forceFill(['data' => ['ip' => '1.2.3.4', FeedThread::KEY => $legacy]])->save();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBe($legacy)
        ->and($node['data'])->toBe(['ip' => '1.2.3.4']);

    // Nothing was rewritten on the way past: the row is still unversioned.
    expect($activity->fresh()->data[FeedThread::KEY])->toBe($legacy);
});

it('defines a missing version as 1, forever', function () {
    // Not a fallback. Every row written between 2026-09-06 and the commit
    // that added `$v` carries no version key, and 1 is the only thing those
    // rows can be. This is the assertion that fails if someone "simplifies"
    // the default to FeedThread::version().
    expect(FeedThread::versionOf(['text' => 'Hi']))->toBe(1)
        ->and(FeedThread::versionOf([]))->toBe(1)
        ->and(FeedThread::versionOf('not a thread'))->toBe(1)
        // A `$v` that is not a version is not a version.
        ->and(FeedThread::versionOf(['$v' => 'two']))->toBe(1)
        ->and(FeedThread::versionOf(['$v' => 0]))->toBe(1)
        // And a real one is read as itself.
        ->and(FeedThread::versionOf(['$v' => 2]))->toBe(2)
        ->and(FeedThread::versionOf(FeedThread::make(text: 'Hi')->toArray()))->toBe(FeedThread::version());
});

it('reads a row from a newer core without throwing and without losing what it recognises', function () {
    // Total by contract, for `Detail`'s reason: an unrecognised `$from` is a
    // row a newer writer put there, and it is in the database either way.
    $future = ['text' => 'From the future.', 'by' => 'Sam', 'kind' => 'replied', 'replies' => 4, 'truncated' => false, '$v' => 99, 'tone' => 'wry'];

    expect(FeedThread::upgrade($future, 99))->toBeArray();

    $thread = FeedThread::fromArray($future);

    expect($thread->text)->toBe('From the future.')
        ->and($thread->replies)->toBe(4)
        // And the shape handed on is this version's five keys, with no
        // stowaways from a vocabulary this core does not know.
        ->and($thread->toPayload())->toBe([
            'text' => 'From the future.',
            'by' => 'Sam',
            'kind' => 'replied',
            'replies' => 4,
            'truncated' => false,
        ]);
});

it('keeps the version out of the Activity Streams document', function () {
    // A version is our storage's business, not a peer's. AS2 has no term for
    // it and `ns.storyfeed.dev` is not minting one.
    $activity = threadActivity(FeedThread::make(text: 'Shipped.', replies: 1), 'TN-AS2V');

    $json = json_encode(app(ActivitySerializer::class)->activity($activity));

    expect($json)->not->toContain('$v')
        ->and($json)->not->toContain('"99"');
});
