<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Body\Prose;
use Storyfeed\Contracts\FeedSource;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\DatabaseSource;
use Storyfeed\Sources\SourceItem;
use Storyfeed\Sources\SourceManager;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function releases(): array
{
    return [
        [
            'verb' => 'ship',
            'actor' => 'Storyfeed',
            'object' => ['type' => 'release', 'label' => 'v0.17.0', 'url' => 'https://github.com/storyfeed/storyfeed/releases/tag/v0.17.0'],
            'published_at' => '2026-09-01 09:00',
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-09-01',
        ],
        [
            'verb' => 'ship',
            'actor' => 'Storyfeed',
            'object' => ['type' => 'release', 'label' => 'v0.18.0', 'id' => '0.18', 'data' => ['notes' => 12]],
            'published_at' => '2026-09-20 09:00',
            'data' => ['breaking' => false],
            'body' => 'Feed sources and the array source.',
        ],
    ];
}

it('reads a named source from config', function () {
    config()->set('storyfeed.sources.changelog', ['driver' => 'array', 'items' => releases()]);
    Story::for('release')->verb('ship')->headline(':actor shipped :object');

    $items = Storyfeed::feed()->source('changelog')->get()->items();

    expect($items)->toHaveCount(2)
        ->and($items[0]['headline_template'])->toBe(':actor shipped :object')
        ->and($items[0]['actor'])->toMatchArray(['type' => 'storyfeed.party', 'id' => 'storyfeed', 'label' => 'Storyfeed'])
        ->and($items[0]['object'])->toMatchArray([
            'type' => 'release', 'id' => '0.18', 'label' => 'v0.18.0', 'url' => null, 'data' => ['notes' => 12],
            'body' => [Prose::make('Feed sources and the array source.')->toArray()],
        ])
        ->and($items[0]['data'])->toBe(['breaking' => false])
        ->and($items[0]['published_at'])->toBe('2026-09-20T09:00:00.000000Z')
        ->and($items[1])->toMatchArray(['starts_at' => '2026-08-01T00:00:00.000000Z', 'ends_at' => '2026-09-01T00:00:00.000000Z'])
        ->and($items[1]['object'])->toMatchArray([
            'id' => 'v0.17.0', 'url' => 'https://github.com/storyfeed/storyfeed/releases/tag/v0.17.0', 'body' => null,
        ]);
});

it('reads a source without a query', function () {
    Story::for('release')->verb('ship')->headline(':actor shipped :object');
    $source = new ArraySource(releases());

    DB::enableQueryLog();

    $page = Storyfeed::feed()->actor('Storyfeed')->source($source)->only('ship')->live()->get();

    expect($page->items())->toHaveCount(2)
        ->and($page['sync_token'])->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('registers drivers with extend(), as Storage does', function () {
    config()->set('storyfeed.sources.roadmap', ['driver' => 'github', 'repo' => 'storyfeed/storyfeed']);

    Storyfeed::extend('github', function ($app, array $config) {
        expect($app)->toBe(app())->and($config)->toBe(['driver' => 'github', 'repo' => 'storyfeed/storyfeed']);

        return new class($config) implements FeedSource
        {
            public function __construct(protected array $config) {}

            public function items(): iterable
            {
                yield SourceItem::make('close', '2026-09-09 12:00', actor: 'GitHub', object: ['type' => 'issue', 'label' => '#50', 'url' => "https://github.com/{$this->config['repo']}/issues/50"]);
            }
        };
    });

    $items = Storyfeed::feed()->source('roadmap')->get()->items();

    expect($items)->toHaveCount(1)
        ->and($items[0]['object']['url'])->toBe('https://github.com/storyfeed/storyfeed/issues/50')
        ->and(Storyfeed::source('roadmap'))->toBe(Storyfeed::source('roadmap'));
});

it('defaults to the database', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('create', $delivery)->publish();

    expect(Storyfeed::source())->toBeInstanceOf(DatabaseSource::class)
        ->and(Storyfeed::source('database'))->toBeInstanceOf(DatabaseSource::class)
        ->and(Storyfeed::feed()->source('database')->get()->items())->toHaveCount(1)
        ->and(iterator_to_array(Storyfeed::source()->items()))->toHaveCount(1);

    config()->set('storyfeed.sources', ['changelog' => ['driver' => 'array']]);
    app(SourceManager::class)->forgetSource('database');

    expect(Storyfeed::source('database'))->toBeInstanceOf(DatabaseSource::class);
});

it('refuses a source it cannot build', function (array $config, string $message) {
    config()->set('storyfeed.sources.broken', $config);
    Storyfeed::extend('nothing', fn () => 'not a source');

    expect(fn () => Storyfeed::feed()->source('broken'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no driver' => [[], 'Source [broken] does not have a configured driver.'],
    'unknown driver' => [['driver' => 'ftp'], 'Driver [ftp] is not supported.'],
    'not a source' => [['driver' => 'nothing'], 'Driver [nothing] must return a Storyfeed\Contracts\FeedSource, string returned.'],
]);

it('refuses an undefined source', function () {
    expect(fn () => Storyfeed::feed()->source('missing'))
        ->toThrow(InvalidArgumentException::class, 'Source [missing] does not have a configured driver.');
});

it('throws on what only stored history can answer', function (Closure $call, string $name) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    expect(fn () => $call(Storyfeed::feed()->source(new ArraySource(releases())), $user)->get())
        ->toThrow(FeedMisconfigured::class, "cannot read {$name}")
        // Whichever comes first.
        ->and(fn () => $call(Storyfeed::feed(), $user)->source(new ArraySource(releases()))->get())
        ->toThrow(FeedMisconfigured::class, "cannot read {$name}");
})->with([
    'involving' => [fn ($feed, $user) => $feed->involving($user), 'involving()'],
    'involvingDirectly' => [fn ($feed, $user) => $feed->involvingDirectly($user), 'involving()'],
    'involvingType' => [fn ($feed) => $feed->involvingType(User::class), 'involvingType()'],
    'query' => [fn ($feed) => $feed->query(fn ($query) => $query->where('id', '>', 0)), 'query()'],
]);

it('takes models, party names and entities as roles', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1'])->refresh();
    $snapshots = Snapshot::query()->count();

    $items = Storyfeed::feed()->source(new ArraySource([
        SourceItem::make('dispatch', now()->subMinute(), actor: $sally, object: $delivery, target: 'Courier Bot', body: [Prose::make('Left the depot.')]),
    ]))->get()->items();

    expect($items[0]['actor'])->toMatchArray(['type' => $sally->getMorphClass(), 'id' => (string) $sally->getKey(), 'label' => 'Sally'])
        ->and($items[0]['object'])->toMatchArray(['label' => 'Delivery #TN-1', 'url' => '/deliveries/'.$delivery->getKey()])
        ->and($items[0]['object']['body'])->toBe([Prose::make('Left the depot.')->toArray()])
        ->and($items[0]['target'])->toMatchArray(['type' => 'storyfeed.party', 'id' => 'courier-bot', 'label' => 'Courier Bot'])
        ->and(Snapshot::query()->count())->toBe($snapshots);
});

it('keeps each item\'s id from one read to the next', function () {
    $items = [...releases(), releases()[0]];

    $first = array_column(Storyfeed::feed()->source(new ArraySource($items))->log()->get()->items(), 'id');
    $second = array_column(Storyfeed::feed()->source(new ArraySource($items))->log()->get()->items(), 'id');

    expect($first)->toBe($second)
        ->and(array_unique($first))->toHaveCount(3);
});

it('pages a source with cursorPaginate()', function () {
    $items = collect(range(1, 5))->map(fn ($i) => SourceItem::make('ship', now()->subDays($i), object: ['type' => 'release', 'label' => "v0.{$i}.0"]))->all();
    $feed = Storyfeed::feed()->source(new ArraySource($items))->log();

    $first = $feed->cursorPaginate(2);
    $labels = collect($first->items())->pluck('object.label')->all();
    $next = $feed->cursor($first->nextCursor()->encode())->limit(2)->get();

    expect($labels)->toBe(['v0.1.0', 'v0.2.0'])
        ->and(array_column(array_column($next->items(), 'object'), 'label'))->toBe(['v0.3.0', 'v0.4.0']);
});

it('refuses an item it cannot read', function (array $item, string $message) {
    expect(fn () => Storyfeed::feed()->source(new ArraySource([$item]))->get())
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown key' => [['verb' => 'ship', 'published_at' => 'now', 'summary' => 'x'], 'Unknown source item key [summary]'],
    'no verb' => [['published_at' => 'now'], 'A source item needs [verb].'],
    'no time' => [['verb' => 'ship'], 'A source item needs [published_at].'],
    'dotted verb' => [['verb' => 'release.ship', 'published_at' => 'now'], 'must be a non-empty verb without a dot'],
    'unknown entity key' => [['verb' => 'ship', 'published_at' => 'now', 'object' => ['type' => 'release', 'label' => 'v1', 'href' => '/']], 'Unknown key [href] on the [object] entity'],
    'entity without a label' => [['verb' => 'ship', 'published_at' => 'now', 'object' => ['type' => 'release']], 'The [object] entity needs a [label].'],
    'range ending before it starts' => [['verb' => 'ship', 'published_at' => 'now', 'starts_at' => '2026-09-02', 'ends_at' => '2026-09-01'], 'An activity cannot end'],
    'body without an object' => [['verb' => 'ship', 'published_at' => 'now', 'body' => 'x'], 'A source item with a body needs an object'],
]);

it('refuses an item that is neither an array nor a SourceItem', function () {
    expect(fn () => Storyfeed::feed()->source(new ArraySource(['ship']))->get())
        ->toThrow(InvalidArgumentException::class, 'A source item must be an array or a Storyfeed\Sources\SourceItem, string given.');
});
