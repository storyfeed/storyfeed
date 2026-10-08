<?php

use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\FeedItem;

it('rejects the retired read mode and names Live', function () {
    expect(fn () => Storyfeed::feed()->summary())->toThrow(InvalidArgumentException::class, 'Live');
    expect(fn () => Storyfeed::feed()->summary(Period::Week))->toThrow(InvalidArgumentException::class, 'Live');
    config()->set('storyfeed.grouping.default', 'summary');
    expect(fn () => Storyfeed::feed()->get())->toThrow(InvalidArgumentException::class, 'Live');
    expect(fn () => Group::summary())->toThrow(StoryMisconfigured::class, 'Live');
    expect(fn () => (new GroupBuilder)->summary('made :count approvals'))->toThrow(StoryMisconfigured::class, 'Live');
});

it('writes no Summary partition axes', function () {
    Storyfeed::activity()->actor('Integration')->verb('sync')->publish();
    expect(array_keys(Storyfeed::registeredAxes()))->toBe(['actors', 'actors_target', 'targets', 'object', 'repeat', 'composite', 'batch'])
        ->and(Grouping::query()->where('bucket', 'like', 'summary.%')->count())->toBe(0);
});

it('serializes count-one grammar and excludes the retired payload fields', function () {
    Story::verb('comment')->headline(':actor commented :count times');
    Storyfeed::activity()->actor('Ada')->verb('comment')->publish();
    $item = Storyfeed::feed()->live()->get()->items()[0];
    expect($item['headline_template'])->toBe(':actor commented :count time')
        ->and($item)->not->toHaveKeys(['period', 'phrases', 'phrases_truncated']);
    Storyfeed::activity()->actor('Ada')->verb('comment')->publish();
    expect(Storyfeed::feed()->live()->get()->items()[0])->not->toHaveKeys(['period', 'phrases', 'phrases_truncated']);
});

it('renders count-one grammar with Laravel singular inflection', function (string $template, string $expected) {
    $item = FeedItem::of(['kind' => 'group', 'count' => 1, 'headline_template' => $template]);
    expect($item->headline()->toString())->toBe($expected)
        ->and(strip_tags($item->headline()->toHtml()))->toBe($expected);
    $plural = FeedItem::of(['kind' => 'group', 'count' => 2, 'headline_template' => $template]);
    expect($plural->headline()->toString())->toBe(str_replace(':count', '2', $template));
})->with([
    ['commented :count times', 'commented 1 time'],
    ['made :count approvals', 'made 1 approval'],
    ['added :count items', 'added 1 item'],
]);
