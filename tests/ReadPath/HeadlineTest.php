<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\Models\Activity;
use Storyfeed\Stories\StoryManifest;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * What the registrar changes on the read path: optional segments resolved in
 * core, closure headlines returning a template or finished text, and
 * FeedHeadline::trans() translated in the reader's locale.
 */

/** @return array<string, mixed> */
function confirmNode(bool $actor = true, bool $target = true): array
{
    $pending = $actor
        ? Storyfeed::activity('confirm')->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        : Storyfeed::anonymous()->verb('confirm');

    $pending->object(Delivery::create(['tracking_number' => 'TN-1']));

    if ($target) {
        $pending->target(Customer::create(['name' => 'Acme']));
    }

    $pending->publish();

    return Storyfeed::feed()->get()->toArray()['items'][0];
}

it('resolves optional segments against the roles the activity holds', function (bool $actor, bool $target, string $expected) {
    Story::for(Delivery::class)->verb('confirm')->headline('[:actor ]confirmed :object[ for :target]');

    $node = confirmNode($actor, $target);

    expect($node['headline_template'])->toBe($expected)
        ->and($node['headline'])->toBeNull();
})->with([
    'every role present' => [true, true, ':actor confirmed :object for :target'],
    'no target' => [true, false, ':actor confirmed :object'],
    'no actor' => [false, true, 'confirmed :object for :target'],
    'neither' => [false, false, 'confirmed :object'],
]);

it('never lets a bracket reach the payload', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object[ (rush)][ for :target]');

    expect(confirmNode(target: false)['headline_template'])->toBe(':actor confirmed :object (rush)');
});

it('drops a segment when any role it names is empty', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':object confirmed[ by :actor for :target]');

    expect(confirmNode(target: false)['headline_template'])->toBe(':object confirmed');
});

it('resolves segments in the AS2 summary too', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object[ for :target]');

    confirmNode(target: false);

    $document = serialize_one(Activity::query()->firstOrFail());

    expect($document['summary'])->toBe('Sally confirmed Delivery #TN-1');
});

it('resolves segments against the group: a role no member holds is empty', function () {
    $members = ['actor' => 2, 'object' => 3, 'target' => 0];
    $filled = fn (string $role): bool => ($members[$role] ?? 0) > 0;

    expect(FeedHeadline::resolveSegments(':actors confirmed :count deliveries[ for :targets][ (:others more)]', $filled))
        ->toBe(':actors confirmed :count deliveries (:others more)');
});

it('reads a closure result with a role token as a template', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(
        fn (Activity $activity) => ($activity->data['rush'] ?? false) ? ':actor rushed :object[ to :target]' : ':actor confirmed :object',
    );

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->data(['rush' => true])
        ->publish();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['headline_template'])->toBe(':actor rushed :object')
        ->and($node['headline'])->toBeNull();
});

it('reads a closure result without a role token as finished text', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(fn (Activity $activity) => 'A delivery was confirmed');

    $node = confirmNode();

    expect($node['headline'])->toBe('A delivery was confirmed')
        ->and($node['headline_template'])->toBeNull();
});

it('treats a closure in the array form the same way', function () {
    Storyfeed::grammar(['delivery.confirm' => fn () => ':actor confirmed :object']);

    expect(confirmNode()['headline_template'])->toBe(':actor confirmed :object');
});

it('translates FeedHeadline::trans() in the reader\'s locale, not the boot locale', function () {
    app('translator')->addLines(['feed.confirmed' => ':actor confirmed :object'], 'en');
    app('translator')->addLines(['feed.confirmed' => ':actor a confirmé :object'], 'fr');

    // Registered while the app is in its default locale, as boot is.
    Story::for(Delivery::class)->verb('confirm')->headline(FeedHeadline::trans('feed.confirmed'));
    Storyfeed::grammar(['delivery.ship' => FeedHeadline::trans('feed.confirmed')]);

    confirmNode();

    app()->setLocale('fr');
    $french = Storyfeed::feed()->get()->toArray()['items'][0];

    app()->setLocale('en');
    $english = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($french['headline_template'])->toBe(':actor a confirmé :object')
        ->and($english['headline_template'])->toBe(':actor confirmed :object');

    app()->setLocale('fr');
    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor a confirmé :object');
});

it('uses the anonymous headline for an actorless row, on the type ladder', function () {
    Story::for(Delivery::class)->verb('confirm')
        ->headline(':actor confirmed :object')
        ->anonymousHeadline(':object was confirmed[ for :target]');

    expect(confirmNode(actor: false, target: false)['headline_template'])->toBe(':object was confirmed');
});

it('caches a FeedHeadline and a closure headline', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(FeedHeadline::trans('feed.confirmed'));
    Story::for(Delivery::class)->verb('ship')->headline(static fn () => 'Shipped');

    $this->artisan('storyfeed:cache')->assertSuccessful();

    $cached = app(StoryManifest::class)->read();
    app(StoryManifest::class)->delete();

    // Serialised the way route:cache serialises closure routes, and a
    // closure again once the manifest is required.
    expect($cached['grammar']['delivery.confirm'])->toEqual(FeedHeadline::trans('feed.confirmed'))
        ->and($cached['grammar']['delivery.ship'])->toBeInstanceOf(Closure::class)
        ->and(($cached['grammar']['delivery.ship'])())->toBe('Shipped');
});
