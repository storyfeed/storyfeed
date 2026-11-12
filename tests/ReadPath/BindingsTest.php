<?php

use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\FeedItem;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Literal headline values: `->bind('recipient', $name)`. A user-entered name
 * prints exactly as entered, wherever the headline is drawn.
 */

dataset('literals', [
    'brackets' => 'Pat [x] Example',
    'a role token' => 'Bay :object',
    'an unknown token' => 'Ana :unknown',
    'a backslash' => 'C:\\ drive \\1',
    'a percent' => '100% %s done',
    'html-ish' => '<b>Bo</b> & "Co"',
    'everything' => '[ :actor ] :count $1 \\0 %d <i>',
]);

function sentTo(string $name, string|Closure $headline = ':actor sent :object[ to :recipient]'): Activity
{
    Story::for(Delivery::class)->verb('confirm')->headline($headline);

    return Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->bind('recipient', $name)
        ->publish();
}

it('keeps the token in the template and carries the value in bindings', function (string $name) {
    sentTo($name);

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['headline_template'])->toBe(':actor sent :object to :recipient')
        ->and($node['bindings'])->toBe(['recipient' => $name]);
})->with('literals');

it('stores the bindings with the activity, so the headline is rebuilt at read time', function (string $name) {
    $activity = sentTo($name);

    expect($activity->fresh()->bindings)->toBe(['recipient' => $name]);
})->with('literals');

it('prints the value exactly in the PHP reader', function (string $name) {
    sentTo($name);

    $item = new FeedItem(Storyfeed::feed()->get()->toArray()[0]);

    expect($item->headline()->toString())->toBe("Sally sent Delivery #TN-1 to {$name}")
        ->and($item->headline()->toHtml(fn ($entity) => '['.$entity->toString().']', fn (string $raw) => strtoupper($raw)))
        ->toBe('[Sally] SENT [Delivery #TN-1] TO '.e($name))
        ->and($item->bindings())->toBe(['recipient' => $name]);
})->with('literals');

it('marks a bound value as token text, never an entity', function () {
    sentTo('Bay :object');

    $segments = (new FeedItem(Storyfeed::feed()->get()->toArray()[0]))->headline()->segments();

    expect($segments->last())->toBe(['type' => 'text', 'text' => 'Bay :object', 'token' => 'binding', 'binding' => 'recipient'])
        ->and($segments->where('type', 'entity')->pluck('role')->all())->toBe(['actor', 'object']);
});

it('prints the value exactly, escaped once, in the AS2 summary', function (string $name) {
    $activity = sentTo($name);

    expect(serialize_one($activity)['summary'])->toBe(e("Sally sent Delivery #TN-1 to {$name}"));
})->with('literals');

it('fills a closure headline that names only bound tokens into finished text', function (string $name) {
    $activity = sentTo($name, fn () => 'Sent to :recipient');

    $node = Storyfeed::feed()->get()->toArray()[0];

    expect($node['headline_template'])->toBeNull()
        ->and($node['headline'])->toBe("Sent to {$name}")
        ->and(serialize_one($activity->fresh())['summary'])->toBe(e("Sent to {$name}"));
})->with('literals');

it('keeps an optional segment that names a bound token', function () {
    sentTo('Pat [x] Example', ':actor sent :object[ to :recipient][ for :target]');

    expect(Storyfeed::feed()->get()->toArray()[0]['headline_template'])->toBe(':actor sent :object to :recipient');
});

it('emits null bindings when none are bound', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    expect(Storyfeed::feed()->get()->toArray()[0])->toHaveKey('bindings', null);
});

it('throws for a name that is a role or a core token', function (string $name) {
    Storyfeed::activity('confirm')->bind($name, 'x');
})->with(['actor', 'object', 'targets', 'count', 'others', 'verb'])->throws(InvalidArgumentException::class, 'already a headline token');

it('throws for a name that is not a token', function (string $name) {
    Storyfeed::activity('confirm')->bind($name, 'x');
})->with(['due_date', 'Recipient', '', 'a1', ':recipient'])->throws(InvalidArgumentException::class, 'is not a token');

it('binds through record() and a verb enum', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object for :recipient');

    Storyfeed::record('confirm', Delivery::create(['tracking_number' => 'TN-3']), bind: ['recipient' => 'Pat [x]']);
    ActivityVerb::Confirm->of(Delivery::create(['tracking_number' => 'TN-4']))->bind('recipient', 'Bay :object')->publish();

    expect(Activity::query()->orderBy('id')->pluck('bindings')->all())->toBe([['recipient' => 'Pat [x]'], ['recipient' => 'Bay :object']]);
});

it('names a binding for a group only when every member holds the same text', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed it for :recipient');
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $record = fn (string $name, int $i) => Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "G-{$i}"]))->actor($user)->bind('recipient', $name)->publish();

    $same = collect([$record('Pat [x]', 1), $record('Pat [x]', 2)]);
    $node = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'same', 2, $same));

    expect($node['bindings'])->toBe(['recipient' => 'Pat [x]'])
        ->and($node['headline_template'])->toBe(':actor confirmed it for :recipient');

    $different = collect([$record('Pat [x]', 3), $record('Bay :object', 4)]);
    $node = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'different', 2, $different));

    expect($node['bindings'])->toBeNull()
        ->and($node['headline_template'])->toBeNull()
        ->and($node['children'][1]['bindings'])->toBe(['recipient' => 'Bay :object']);
});

it('adds the bindings column in the additive migration', function () {
    expect(Schema::hasColumn((new Activity)->getTable(), 'bindings'))->toBeTrue();
});
