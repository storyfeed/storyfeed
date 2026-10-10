<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Body\MediaObject;
use Storyfeed\ComposedFeed;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedImage;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\Entry;
use Storyfeed\Tests\Fixtures\Models\Project;
use Workbench\App\Models\User;

beforeEach(function () {
    Relation::morphMap(['project' => Project::class]);
});

/** teylabs.com's projects, as the table holds them: no dates, a manual position. */
function projects(): array
{
    return [
        new Project(['id' => 3, 'name' => 'TalkingFeed', 'slug' => 'talkingfeed', 'tagline' => 'a live feed for a talk', 'position' => 3]),
        new Project(['id' => 1, 'name' => 'InvoiceJam', 'slug' => 'invoicejam', 'tagline' => 'invoices for freelancers', 'position' => 1]),
        new Project(['id' => 2, 'name' => 'Storyfeed', 'slug' => 'storyfeed', 'tagline' => 'activity feeds for Laravel', 'position' => 2]),
    ];
}

function showcase(): ComposedFeed
{
    $feed = Storyfeed::compose()->inOrder();

    foreach (collect(projects())->sortBy('position') as $project) {
        $feed->add(fn (Entry $entry) => $entry
            ->by('Tey Labs')
            ->headline(':object, :tagline', ['object' => $project, 'tagline' => $project->tagline])
            ->body(MediaObject::make(
                subject: $project->name,
                content: $project->tagline,
                image: FeedImage::make()->src("https://teylabs.com/cards/{$project->slug}.png")->alt($project->name),
            )));
    }

    return $feed;
}

it('composes a dateless showcase in the order given', function () {
    $items = showcase()->get()->toArray();

    expect(array_column($items, 'headline_template'))->toBe([
        ':object, invoices for freelancers',
        ':object, activity feeds for Laravel',
        ':object, a live feed for a talk',
    ])
        ->and(array_column($items, 'published_at'))->toBe([null, null, null])
        ->and(array_column($items, 'verb'))->toBe([null, null, null])
        ->and(array_column($items, 'glyph'))->toBe([null, null, null])
        ->and($items[0]['actor'])->toMatchArray(['type' => 'storyfeed.party', 'id' => 'tey-labs', 'label' => 'Tey Labs'])
        ->and($items[0]['object'])->toMatchArray(['type' => 'project', 'id' => '1', 'label' => 'InvoiceJam'])
        ->and($items[0]['object']['link']['href'])->toBe('https://teylabs.com/projects/invoicejam')
        ->and($items[0]['object']['body'])->toBe([MediaObject::make(
            subject: 'InvoiceJam',
            content: 'invoices for freelancers',
            image: FeedImage::make()->src('https://teylabs.com/cards/invoicejam.png')->alt('InvoiceJam'),
        )->toArray()]);
});

it('composes talks as upcoming soonest first, then past most recent first', function () {
    $talks = collect([
        ['name' => 'Laracon EU', 'starts_at' => now()->subMonths(6)],
        ['name' => 'Laracon US', 'starts_at' => now()->subMonths(2)],
        ['name' => 'London Laravel', 'starts_at' => now()->addMonths(3)],
        ['name' => 'GPUG', 'starts_at' => now()->addWeeks(2)],
    ]);

    [$upcoming, $past] = $talks->partition(fn (array $talk) => $talk['starts_at']->isFuture());

    $feed = Storyfeed::compose()->inOrder();

    foreach ([...$upcoming->sortBy('starts_at'), ...$past->sortByDesc('starts_at')] as $talk) {
        $feed->add(fn (Entry $entry) => $entry
            ->by('Jasper')
            ->action('speak', ['type' => 'talk', 'label' => $talk['name']])
            ->headline(':actor speaks at :object')
            ->publishedAt($talk['starts_at']));
    }

    $first = $feed->cursorPaginate(3);
    $rest = $feed->cursorPaginate(3, cursor: $first->nextCursor());

    expect(collect($first->items())->pluck('object.label')->all())->toBe(['GPUG', 'London Laravel', 'Laracon US'])
        ->and(collect($rest->items())->pluck('object.label')->all())->toBe(['Laracon EU'])
        ->and($rest->nextCursor())->toBeNull()
        ->and($first->items()[0]['published_at'])->toBe(now()->addWeeks(2)->toIso8601ZuluString('microsecond'));
});

it('needs none of the stored feed, models included', function () {
    // An app that never ran core's migrations: a connection with no tables.
    config()->set('database.connections.bare', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.default', 'bare');

    expect(DB::connection('bare')->getSchemaBuilder()->getTableListing())->toBe([]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $user = (new User)->forceFill(['id' => 7, 'name' => 'Sally', 'email' => 'sally@example.com']);

    $items = showcase()
        ->add(fn (Entry $entry) => $entry->headline(':actor made this!', ['actor' => $user]))
        ->get()
        ->toArray();

    expect($queries)->toBe([])
        ->and($items)->toHaveCount(4)
        ->and($items[0]['object']['label'])->toBe('InvoiceJam')
        ->and($items[3]['actor'])->toMatchArray(['type' => 'user', 'id' => '7', 'label' => 'Sally'])
        ->and($items[3]['actor']['link']['href'])->toBe('/users/7');
});

it('sets roles from headline replacements, as __() replaces the rest', function () {
    $user = (new User)->forceFill(['id' => 7, 'name' => 'Sally']);

    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline(':actor shipped :object (:version, :Note, :NOTE)', [
            'actor' => $user,
            'object' => ['type' => 'release', 'label' => 'v0.20.0'],
            'version' => 'v0.20',
            'note' => 'breaking',
        ]))
        ->get()
        ->toArray();

    expect($items[0]['headline_template'])->toBe(':actor shipped :object (v0.20, Breaking, BREAKING)')
        ->and($items[0]['actor']['label'])->toBe('Sally')
        ->and($items[0]['object']['label'])->toBe('v0.20.0');
});

it('composes a plain sentence with no roles', function () {
    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline('Opened the doors to the new kitchen'))
        ->get()
        ->toArray();

    expect($items[0])->toMatchArray([
        'headline_template' => 'Opened the doors to the new kitchen',
        'verb' => null,
        'published_at' => null,
        'actor' => null,
        'object' => null,
    ]);
});

it('refuses a headline it cannot keep', function (Closure $entry, string $message) {
    expect(fn () => Storyfeed::compose()->add($entry))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a model under a key that is not a role' => [
        fn (Entry $entry) => $entry->headline(':person made this', ['person' => new User(['name' => 'Sally'])]),
        'The [:person] replacement is a Workbench\App\Models\User model, and a model is a participant',
    ],
    'an entity under a key that is not a role' => [
        fn (Entry $entry) => $entry->headline(':thing landed', ['thing' => ['type' => 'release', 'label' => 'v1']]),
        'The [:thing] replacement must be text, array given.',
    ],
    'a role token with no role' => [
        fn (Entry $entry) => $entry->headline(':actor shipped :object', ['actor' => 'Storyfeed']),
        'The headline [:actor shipped :object] names [:object], but the entry has no object.',
    ],
    'a role given text' => [
        fn (Entry $entry) => $entry->headline(':actor shipped', ['actor' => 42]),
        'The [:actor] replacement fills the [actor] role',
    ],
]);

it('drops an optional segment whose role is empty', function () {
    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline(':actor shipped[ to :target]', ['actor' => 'Storyfeed']))
        ->get()
        ->toArray();

    expect($items[0]['headline_template'])->toBe(':actor shipped');
});

it('prefers the entry\'s own headline to the feed file\'s', function () {
    Story::for('release')->verb('ship')->headline(':actor shipped :object');

    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Storyfeed')->action('ship', ['type' => 'release', 'label' => 'v1'])->headline(':actor released :object'))
        ->get()
        ->toArray();

    expect($items[0]['headline_template'])->toBe(':actor released :object');
});

it('reads newest first without inOrder(), dateless entries last in the order given', function () {
    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline('first undated'))
        ->add(fn (Entry $entry) => $entry->headline('older')->publishedAt(now()->subDays(2)))
        ->add(fn (Entry $entry) => $entry->headline('second undated'))
        ->add(fn (Entry $entry) => $entry->headline('newer')->publishedAt(now()->subDay()))
        ->get()
        ->toArray();

    expect(array_column($items, 'headline_template'))->toBe(['newer', 'older', 'first undated', 'second undated']);
});

it('pages dateless entries by cursor without inOrder()', function () {
    $feed = Storyfeed::compose();

    foreach (range(1, 5) as $i) {
        $feed->add(fn (Entry $entry) => $entry->headline("entry {$i}"));
    }

    $first = $feed->cursorPaginate(2);
    $second = $feed->cursorPaginate(2, cursor: $first->nextCursor());
    $third = $feed->cursorPaginate(2, cursor: $second->nextCursor());

    expect(collect([...$first->items(), ...$second->items(), ...$third->items()])->pluck('headline_template')->all())
        ->toBe(['entry 1', 'entry 2', 'entry 3', 'entry 4', 'entry 5'])
        ->and($third->nextCursor())->toBeNull();
});

it('reads as Log unless it asks for live()', function () {
    config()->set('storyfeed.grouping.default', 'live');

    $feed = Storyfeed::compose();

    foreach (range(1, 3) as $i) {
        $feed->add(fn (Entry $entry) => $entry->by('Storyfeed')->action('ship', ['type' => 'release', 'label' => 'v1'])->publishedAt(now()->subMinutes($i)));
    }

    expect($feed->get())->toHaveCount(3)
        ->and($feed->live()->get())->toHaveCount(1)
        ->and($feed->live()->get()->first()['kind'])->toBe('group');
});

it('refuses live() on a feed kept in order', function () {
    $feed = Storyfeed::compose()->add(fn (Entry $entry) => $entry->headline('x'))->inOrder();

    expect($feed->get())->toHaveCount(1)
        ->and(fn () => $feed->live()->get())->toThrow(InvalidArgumentException::class, 'The [compose] feed is kept in order, so it cannot read live()');
});

it('filters and limits a composed feed as it does a source', function () {
    $feed = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Storyfeed')->action('ship', ['type' => 'release', 'label' => 'v1']))
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->action('ship', ['type' => 'release', 'label' => 'v2']))
        ->add(fn (Entry $entry) => $entry->by('Storyfeed')->action('tag', ['type' => 'release', 'label' => 'v3']))
        ->inOrder();

    expect((clone $feed)->actor('Storyfeed')->get()->pluck('object.label')->all())->toBe(['v1', 'v3'])
        ->and((clone $feed)->only('ship')->get()->pluck('object.label')->all())->toBe(['v1', 'v2'])
        ->and((clone $feed)->limit(1)->get()->pluck('object.label')->all())->toBe(['v1']);
});

it('reads its own entries, never a source', function () {
    expect(fn () => Storyfeed::compose()->source('changelog'))
        ->toThrow(LogicException::class, 'A composed feed reads the entries added to it');
});

it('reads a verbless entry solo in a live read', function () {
    $feed = Storyfeed::compose()->live();

    foreach (range(1, 3) as $i) {
        $feed->add(fn (Entry $entry) => $entry->headline(':actor said hello', ['actor' => 'Storyfeed'])->publishedAt(now()->subMinutes($i)));
    }

    expect($feed->get()->pluck('kind')->all())->toBe(['activity', 'activity', 'activity']);
});

it('refuses a model role that is not Feedable, which would read with no label', function () {
    $plain = new class extends Model {};

    expect(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->headline(':object shipped', ['object' => $plain])))
        ->toThrow(InvalidArgumentException::class, 'is not Feedable, so it would read with no label. Pass an entity array')
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->by($plain)))
        ->toThrow(InvalidArgumentException::class, 'The [actor] role is a')
        ->and(fn () => Storyfeed::feed()->source(new ArraySource([
            ['verb' => 'ship', 'published_at' => 'now', 'object' => $plain],
        ]))->get())
        ->toThrow(InvalidArgumentException::class, 'The [object] role is a');
});

it('adds an entry for each item with addMany()', function () {
    $items = Storyfeed::compose()
        ->addMany(collect(projects())->sortBy('position'), fn (Project $project, Entry $entry) => $entry
            ->by('Tey Labs')
            ->headline(':object, :tagline', ['object' => $project, 'tagline' => $project->tagline]))
        ->inOrder()
        ->get();

    expect($items->pluck('object.label')->all())->toBe(['InvoiceJam', 'Storyfeed', 'TalkingFeed']);
});

it('composes an order\'s status progression, oldest first, in the feed file\'s wording', function () {
    Story::for('order')->verb('place')->headline(':actor placed :object');
    Story::for('order')->verb('confirm')->headline(':actor confirmed :object');
    Story::for('order')->verb('collect')->headline(':actor collected :object');

    $order = ['type' => 'order', 'label' => 'Order #1042', 'id' => '1042'];
    $steps = [
        ['place', 'Dana', now()->subHours(3)],
        ['confirm', 'The kitchen', now()->subHours(2)],
        ['collect', 'Dana', now()->subHour()],
    ];

    $items = Storyfeed::compose()
        ->addMany($steps, fn (array $step, Entry $entry) => $entry->by($step[1])->action($step[0], $order)->publishedAt($step[2]))
        ->inOrder()
        ->get()
        ->toArray();

    expect(array_column($items, 'verb'))->toBe(['place', 'confirm', 'collect'])
        ->and(array_column($items, 'headline_template'))->toBe([':actor placed :object', ':actor confirmed :object', ':actor collected :object'])
        ->and($items[0]['published_at'])->toBe(now()->subHours(3)->toIso8601ZuluString('microsecond'));
});
