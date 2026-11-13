<?php

use Storyfeed\ActivityContext;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedHeadline;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Feed-aware headlines: a headline closure reads the feed it renders for,
 * through ActivityContext::feed(), viewing() and isViewing(), so the same
 * activity can leave its subject unnamed on the subject's own feed.
 */

function feedAwareStory(): void
{
    Story::for(Delivery::class)->verb('confirm')->headline(
        fn (ActivityContext $c) => $c->isViewing($c->object())
            ? FeedHeadline::make(':actor confirmed :label')->bind('label', 'R1')
            : FeedHeadline::make(':actor confirmed :object'),
    );
}

function feedAwareActivity(string $tn = 'A-8373'): array
{
    $delivery = Delivery::create(['tracking_number' => $tn]);
    $activity = Storyfeed::activity('confirm', $delivery)
        ->actor(User::firstOrCreate(['email' => 's@example.com'], ['name' => 'Sally']))
        ->publish();

    return [$delivery, $activity];
}

it('reads the same activity differently on its subject feed and on the global feed', function () {
    feedAwareStory();
    [$delivery] = feedAwareActivity();

    $own = $delivery->storyfeed()->get()->toArray()[0];
    $global = Storyfeed::feed()->get()->toArray()[0];

    expect($own['headline_template'])->toBe(':actor confirmed :label')
        ->and($own['bindings'])->toBe(['label' => 'R1'])
        ->and($global['headline_template'])->toBe(':actor confirmed :object')
        ->and($global['bindings'])->toBeNull();
});

it('reports the named feed and the viewed model to a headline closure', function () {
    $seen = [];
    Story::for(Delivery::class)->verb('confirm')->headline(function (ActivityContext $c) use (&$seen) {
        $seen[] = [$c->feed(), $c->viewing()?->getKey()];

        return ':actor confirmed :object';
    });
    Storyfeed::feeds(['desk' => fn (FeedBuilder $feed) => $feed]);
    [$delivery] = feedAwareActivity();

    Storyfeed::feed('desk')->get()->toArray();
    Storyfeed::feed('desk')->involving($delivery)->get()->toArray();
    Storyfeed::feed()->context($delivery)->get()->toArray();
    Storyfeed::feed()->get()->toArray();

    expect($seen)->toBe([['desk', null], ['desk', $delivery->getKey()], [null, $delivery->getKey()], [null, null]]);
});

it('views nothing on a null context and compares by morph alias and key', function () {
    $delivery = Delivery::create(['tracking_number' => 'N-1']);
    $other = Delivery::create(['tracking_number' => 'N-2']);
    $entity = new FeedContext(type: $delivery->getMorphClass(), key: $delivery->getKey());

    $none = new ActivityContext(object: $entity);
    $viewing = new ActivityContext(object: $entity, viewing: $delivery);

    expect($none->feed())->toBeNull()
        ->and($none->viewing())->toBeNull()
        ->and($none->isViewing($entity))->toBeFalse()
        ->and($none->isViewing(null))->toBeFalse()
        ->and($viewing->isViewing($entity))->toBeTrue()
        ->and($viewing->isViewing($delivery))->toBeTrue()
        ->and($viewing->isViewing($other))->toBeFalse()
        ->and($viewing->isViewing(new FeedContext(type: 'user', key: $delivery->getKey())))->toBeFalse()
        ->and($viewing->isViewing(null))->toBeFalse();
});

it('does not reuse a headline resolved for another viewing feed', function () {
    feedAwareStory();
    [$delivery, $activity] = feedAwareActivity();
    $activity = $activity->fresh();
    $slice = GroupSlice::solo($activity);

    $presenter = app(NodePresenter::class)->forViewing($delivery);
    $own = $presenter->node($slice);
    $global = $presenter->forViewing(null)->node($slice);

    expect($own['headline_template'])->toBe(':actor confirmed :label')
        ->and($global['headline_template'])->toBe(':actor confirmed :object');
});

it('resolves group members against the viewing feed', function () {
    feedAwareStory();
    [$delivery, $a] = feedAwareActivity('G-1');
    $b = Storyfeed::activity('confirm', $delivery)->actor(User::first())->publish();
    $members = collect([$a->fresh(), $b->fresh()]);

    $own = app(NodePresenter::class)->forViewing($delivery)->groupNode(GroupSlice::group('repeat', 'own', 2, $members));
    $global = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'global', 2, $members->map->fresh()));

    expect($own['bindings'])->toBe(['label' => 'R1'])
        ->and($own['children'][0]['headline_template'])->toBe(':actor confirmed :label')
        ->and($global['bindings'])->toBeNull()
        ->and($global['children'][0]['headline_template'])->toBe(':actor confirmed :object');
});

it('gives the AS2 summary no viewing feed and the default wording', function () {
    $seen = [];
    Story::for(Delivery::class)->verb('confirm')->headline(function (ActivityContext $c) use (&$seen) {
        $seen[] = [$c->feed(), $c->viewing()];

        return $c->isViewing($c->object()) ? ':actor confirmed it' : ':actor confirmed :object';
    });
    [, $activity] = feedAwareActivity();

    expect(serialize_one($activity->fresh())['summary'])->toBe('Sally confirmed Delivery #A-8373')
        ->and($seen)->toBe([[null, null]]);
});
