<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;

it('renders a fluent group closure with the true count and sampled members before and after caching', function (bool $cached) {
    Story::verb('inspect')->grouped(Group::repeat()->headline(
        static fn (GroupSlice $group): string => $group->count.' inspections; '.$group->members->pluck('data.name')->join(', ')
    ));

    if ($cached) {
        $this->artisan('storyfeed:cache')->assertSuccessful();
        try {
            $manifest = app(StoryManifest::class)->read();
            Storyfeed::aggregateGrammar($manifest['aggregateGrammar']);
        } finally {
            app(StoryManifest::class)->delete();
        }
    }

    $members = collect([
        new Activity(['verb' => 'inspect', 'published_at' => now(), 'data' => ['name' => 'First']]),
        new Activity(['verb' => 'inspect', 'published_at' => now(), 'data' => ['name' => 'Second']]),
    ]);
    $node = app(NodePresenter::class)->groupNode(GroupSlice::group('repeat', 'closure', 12, $members));

    expect($node['headline'])->toBe('12 inspections; First, Second')
        ->and($node['headline_template'])->toBeNull();
})->with([false, true]);

it('includes a group closure in request-action presentation fingerprints', function () {
    $one = Verb::make('*.inspect')->grouped(Group::repeat()->headline(static fn (GroupSlice $group): string => 'First '.$group->count));
    $two = Verb::make('*.inspect')->grouped(Group::repeat()->headline(static fn (GroupSlice $group): string => 'Second '.$group->count));

    expect($one->compiledParts()['groups'])->not->toBe($two->compiledParts()['groups']);
});

it('refuses to cache an unserialisable group closure without leaving a manifest', function () {
    $connection = new PDO('sqlite::memory:');
    Story::verb('inspect')->grouped(Group::repeat()->headline(
        static fn (GroupSlice $group): string => $connection->getAttribute(PDO::ATTR_DRIVER_NAME).' '.$group->count
    ));

    $this->artisan('storyfeed:cache')
        ->expectsOutputToContain('nothing was cached')
        ->expectsOutputToContain('aggregateGrammar[repeat.inspect]')
        ->assertFailed();

    expect(app(StoryManifest::class)->exists())->toBeFalse();
});
