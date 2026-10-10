<?php

use Storyfeed\ActivityContext;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('uses actorless grammar only for an authored verb with no recorded actor', function () {
    Story::fallback()->headline(':actor confirmed :object');
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    $before = Storyfeed::feed()->get()->toArray()[0];

    Story::verb('publish')->anonymousHeadline(':object was published');
    expect(Storyfeed::feed()->get()->toArray()[0])->toBe($before);

    Story::verb('confirm')->anonymousHeadline(':object was confirmed');
    $after = Storyfeed::feed()->get()->toArray()[0];
    expect($after)->toBe(array_replace($before, ['headline_template' => ':object was confirmed']))
        ->and($after['actor'])->toBeNull()
        ->and($activity->fresh()->actor_type)->toBeNull();
});

it('preserves null headlines when neither voice is authored', function () {
    Storyfeed::activity('unknown')->publish();
    $item = Storyfeed::feed()->get()->toArray()[0];
    expect($item['headline_template'])->toBeNull()->and($item['headline'])->toBeNull();
});

it('never invokes actorless grammar for a known participant', function (string $kind) {
    $actor = $kind === 'party' ? Party::make('Warehouse') : User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
    Story::fallback()->headline(':actor confirmed :object');
    $called = false;
    Story::verb('confirm')->anonymousHeadline(function () use (&$called) {
        $called = true;

        return 'Wrong voice';
    });
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->actor($actor)->publish();
    $item = Storyfeed::feed()->get()->toArray()[0];
    expect($item['headline_template'])->toBe(':actor confirmed :object')
        ->and($item['actor'])->not->toBeNull()->and($called)->toBeFalse();
})->with(['party', 'model']);

it('does not mistake unresolved or partial identity for an absent actor', function ($type, $id) {
    Story::fallback()->headline(':actor acted');
    Story::verb('confirm')->anonymousHeadline('Confirmed');
    $activity = new Activity(['verb' => 'confirm', 'actor_type' => $type, 'actor_id' => $id, 'published_at' => now()]);
    $item = app(NodePresenter::class)->activityNode($activity);
    expect($item['headline_template'])->toBe(':actor acted');
})->with([['user', 999], ['user', null], [null, 999]]);

it('pre-renders actorless closures with the activity using the existing headline contract', function () {
    Story::verb('confirm')->anonymousHeadline(fn (ActivityContext $activity) => "Recorded {$activity->verb()}");
    Storyfeed::activity('confirm')->publish();
    $item = Storyfeed::feed()->get()->toArray()[0];
    expect($item['headline_template'])->toBeNull()->and($item['headline'])->toBe('Recorded confirm');
});

it('leaves aggregate headlines unchanged while giving their children actorless voices', function () {
    Story::fallback()->grouped(Group::on('*')->headline(':count confirmations'));
    foreach (range(1, 2) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }
    $before = Storyfeed::feed()->get()->toArray()[0];
    Story::verb('confirm')->anonymousHeadline(':object was confirmed');
    $after = Storyfeed::feed()->get()->toArray()[0];
    expect($after['kind'])->toBe('group')
        ->and($after['headline_template'])->toBe($before['headline_template'])
        ->and($after['children'][0]['headline_template'])->toBe(':object was confirmed');
});
