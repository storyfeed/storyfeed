<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('defers translated group templates to the reader locale through an on-disk cache roundtrip', function (bool $cached, bool $shorthand) {
    app()->setLocale('en');
    app('translator')->addLines(['feed.inspections' => ':actor inspected :count files', 'feed.summary' => 'inspected :count files'], 'en');
    app('translator')->addLines(['feed.inspections' => ':actor a inspecté :count fichiers', 'feed.summary' => 'a inspecté :count fichiers'], 'fr');

    $definition = Story::verb('inspect');
    if ($shorthand) {
        $definition->grouped(fn (GroupBuilder $group) => $group
            ->repeat(FeedHeadline::trans('feed.inspections'))
            ->summary(FeedHeadline::trans('feed.summary')));
    } else {
        $definition->grouped(
            Group::repeat()->headline(FeedHeadline::trans('feed.inspections')),
            Group::summary()->headline(FeedHeadline::trans('feed.summary')),
        );
    }
    Storyfeed::compileStories();

    $user = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $members = collect(range(1, 2))->map(fn ($i) => Storyfeed::activity('inspect', Delivery::create(['tracking_number' => "TRANS-{$i}"]))->actor($user)->publish());
    $slice = GroupSlice::group('repeat', 'translated', 12, $members);

    if ($cached) {
        $manifestStore = app(StoryManifest::class);
        $checkout = realpath(dirname(__DIR__, 2));
        $cacheDirectory = realpath(dirname($manifestStore->path()));
        expect($checkout)->not->toBeFalse()
            ->and($cacheDirectory)->not->toBeFalse();
        $cachePath = realpath($manifestStore->path()) ?: $cacheDirectory.DIRECTORY_SEPARATOR.basename($manifestStore->path());
        expect($cachePath)->toStartWith($checkout.DIRECTORY_SEPARATOR);

        try {
            $this->artisan('storyfeed:cache')->assertSuccessful();
            $manifest = app(StoryManifest::class)->read();
            expect($manifest['aggregateGrammar']['repeat.inspect'])->toEqual(FeedHeadline::trans('feed.inspections'));
            app(StoryManifest::class)->apply(Storyfeed::getFacadeRoot());
            Storyfeed::compileStories();
        } finally {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }
    }

    app()->setLocale('fr');
    $french = app(NodePresenter::class)->groupNode($slice);
    expect(Storyfeed::summaryTemplate('inspect'))->toBe('a inspecté :count fichiers');
    app()->setLocale('en');
    $english = app(NodePresenter::class)->groupNode($slice);

    expect($french['headline_template'])->toBe(':actor a inspecté :count fichiers')
        ->and($english['headline_template'])->toBe(':actor inspected :count files')
        ->and($french['headline'])->toBeNull()
        ->and($english['headline'])->toBeNull()
        ->and($french['count'])->toBe(12)
        ->and($french['actor']['label'])->toBe('Dana');

    Artisan::call('storyfeed:list', ['--json' => true]);
    $row = collect(json_decode(Artisan::output(), true))->firstWhere('verb', 'inspect');
    expect($row['groups'])->toBe(['repeat' => 'trans(feed.inspections)', 'summary' => 'trans(feed.summary)']);
})->with([false, true])->with([false, true]);

it('keeps literal group token and unknown-axis validation', function () {
    Story::verb('inspect')->grouped(Group::repeat()->headline(':actor inspected :object'));
    expect(fn () => Storyfeed::compileStories())->toThrow(StoryMisconfigured::class, 'does not pin it');
});

it('rejects a modern group on an unregistered axis', function () {
    Story::verb('inspect')->grouped(Group::on('unknown')->headline(':count inspections'));
    expect(fn () => Storyfeed::compileStories())->toThrow(StoryMisconfigured::class, 'not registered');
});

it('renders a fluent group closure with the true count and sampled members before and after caching', function (bool $cached) {
    Story::verb('inspect')->grouped(Group::repeat()->headline(
        static fn (GroupSlice $group): string => $group->count.' inspections; '.$group->members->pluck('data.name')->join(', ')
    ));

    if ($cached) {
        $this->artisan('storyfeed:cache')->assertSuccessful();
        try {
            $manifest = app(StoryManifest::class)->read();
            // Exercise the actual cached snapshot, including lazy closure hydration.
            Storyfeed::useCompiledStories($manifest);
            Storyfeed::compileStories();
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
