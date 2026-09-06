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
