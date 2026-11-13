<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Render-time bindings: a headline closure returns
 * `FeedHeadline::make(...)->bind('recipient', $name)`. The value is supplied
 * when the feed is read and stored nowhere, and prints exactly as given.
 */

dataset('render literals', [
    'brackets' => 'Pat [x] Example',
    'a role token' => 'Bay :object',
    'an unknown token' => 'Ana :unknown',
    'a backslash' => 'C:\\ drive \\1',
    'a percent' => '100% %s done',
    'html-ish' => '<b>Bo</b> & "Co"',
    'everything' => '[ :actor ] :count $1 \\0 %d <i>',
]);

function renderSent(string $name, string $template = ':actor sent :object[ to :recipient]', array $stored = []): Activity
{
    Story::for(Delivery::class)->verb('confirm')
        ->headline(fn () => FeedHeadline::make($template)->bind('recipient', $name));

    $pending = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']));

    foreach ($stored as $key => $value) {
        $pending->bind($key, $value);
    }

    return $pending->publish();
}

it('carries a render-time value in the payload bindings, storing nothing', function (string $name) {
    $activity = renderSent($name);

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['headline_template'])->toBe(':actor sent :object to :recipient')
        ->and($node['bindings'])->toBe(['recipient' => $name])
        ->and($activity->fresh()->bindings)->toBeNull();
})->with('render literals');

it('prints a render-time value exactly in the PHP reader', function (string $name) {
    renderSent($name);

    $item = new FeedItem(Storyfeed::feed()->get()->toArray()[0]);

    expect($item->headline()->toString())->toBe("Sally sent Delivery #TN-1 to {$name}")
        ->and($item->headline()->toHtml(fn ($entity) => '['.$entity->toString().']', fn (string $raw) => strtoupper($raw)))
        ->toBe('[Sally] SENT [Delivery #TN-1] TO '.e($name));
})->with('render literals');

it('prints a render-time value exactly, escaped once, in the AS2 summary', function (string $name) {
    $activity = renderSent($name);

    expect(serialize_one($activity->fresh())['summary'])->toBe(e("Sally sent Delivery #TN-1 to {$name}"));
})->with('render literals');

it('fills finished closure text with a render-time value', function (string $name) {
    $activity = renderSent($name, 'Sent to :recipient');

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['headline_template'])->toBeNull()
        ->and($node['headline'])->toBe("Sent to {$name}")
        ->and($node['bindings'])->toBe(['recipient' => $name])
        ->and(serialize_one($activity->fresh())['summary'])->toBe(e("Sent to {$name}"));
})->with('render literals');

it('merges stored and render-time bindings, the render-time value winning', function (string $name) {
    $activity = renderSent($name, ':actor sent :object to :recipient via :carrier', ['carrier' => 'Old [x]', 'recipient' => 'Stored']);

    $node = Storyfeed::feed()->get()->toArray()[0];
    $item = new FeedItem($node);

    expect($node['bindings'])->toBe(['carrier' => 'Old [x]', 'recipient' => $name])
        ->and($item->headline()->toString())->toBe("Sally sent Delivery #TN-1 to {$name} via Old [x]")
        ->and(serialize_one($activity->fresh())['summary'])->toBe(e("Sally sent Delivery #TN-1 to {$name} via Old [x]"))
        ->and($activity->fresh()->bindings)->toBe(['carrier' => 'Old [x]', 'recipient' => 'Stored']);
})->with('render literals');

it('renders an activity with nothing stored and a static headline as before', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object');
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-9']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))->publish();

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['bindings'])->toBeNull()
        ->and((new FeedItem($node))->headline()->toString())->toBe('Sally confirmed Delivery #TN-9');
});

it('binds on a registered FeedHeadline', function () {
    Story::for(Delivery::class)->verb('confirm')
        ->headline(FeedHeadline::make(':actor sent :object to :recipient')->bind('recipient', 'Pat [x]'));
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-5']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))->publish();

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['bindings'])->toBe(['recipient' => 'Pat [x]'])
        ->and((new FeedItem($node))->headline()->toString())->toBe('Sally sent Delivery #TN-5 to Pat [x]');
});

it('throws for a render-time name that is a role or a core token', function (string $name) {
    FeedHeadline::make(':actor')->bind($name, 'x');
})->with(['actor', 'object', 'targets', 'count', 'others', 'verb'])->throws(InvalidArgumentException::class, 'already a headline token');

it('throws for a render-time name that is not a token', function (string $name) {
    FeedHeadline::make(':actor')->bind($name, 'x');
})->with(['due_date', 'Recipient', '', ':recipient'])->throws(InvalidArgumentException::class, 'is not a token');

it('names a render-time binding for a group only when every member holds the same text', function (string $name) {
    $names = ['G-1' => $name, 'G-2' => $name, 'G-3' => $name, 'G-4' => 'Other'];
    Story::for(Delivery::class)->verb('confirm')->headline(
        fn ($c) => FeedHeadline::make(':actor confirmed it for :recipient')
            ->bind('recipient', $names[Activity::query()->find($c->get('n'))?->object?->tracking_number] ?? 'none'),
    );

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $record = function (string $tn) use ($user): Activity {
        $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => $tn]))->actor($user)->publish();
        $activity->update(['data' => ['n' => $activity->id]]);

        return $activity->fresh();
    };

    $same = collect([$record('G-1'), $record('G-2')]);
    $node = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'same', 2, $same));

    expect($node['bindings'])->toBe(['recipient' => $name])
        ->and($node['children'][0]['bindings'])->toBe(['recipient' => $name]);

    $different = collect([$record('G-3'), $record('G-4')]);
    $node = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'different', 2, $different));

    expect($node['bindings'])->toBeNull()
        ->and($node['children'][1]['bindings'])->toBe(['recipient' => 'Other']);
})->with('render literals');
