<?php

use Storyfeed\ComposedFeed;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Anchor;
use Storyfeed\Grouping\Group;
use Storyfeed\Sources\Entry;

/**
 * A release's changes, each naming the release as its context, between two
 * entries that name none: what `groupBy(Group::byContext())` folds.
 */
function release(): ComposedFeed
{
    $release = ['type' => 'release', 'label' => 'v2.3'];

    return Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->action('post', ['type' => 'note', 'label' => 'Hello'])->publishedAt(now()->subDays(3)))
        ->addMany(['Faster reads', 'Context groups', 'Code bodies'], fn (string $change, Entry $entry, int $i) => $entry
            ->by('Storyfeed')
            ->action('ship', ['type' => 'change', 'label' => $change])
            ->context($release)
            ->publishedAt(now()->subDays(2)->addMinutes($i)))
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->action('post', ['type' => 'note', 'label' => 'Goodbye'])->publishedAt(now()->subDay()));
}

it('folds the entries that name one context into one group node', function () {
    $items = release()
        ->groupBy(Group::byContext()->headline(':context shipped :count changes'))
        ->get()
        ->toArray();

    expect(array_column($items, 'kind'))->toBe(['activity', 'group', 'activity'])
        ->and($items[1]['axis'])->toBe('context')
        ->and($items[1]['count'])->toBe(3)
        ->and($items[1]['headline_template'])->toBe(':context shipped :count changes')
        ->and($items[1]['context'])->toMatchArray(['type' => 'release', 'id' => 'v2.3', 'label' => 'v2.3'])
        ->and($items[1]['children_truncated'])->toBeFalse()
        // Newest first, as the feed reads; the group stands at the newest one's time.
        ->and(array_column(array_column($items[1]['children'], 'object'), 'label'))->toBe(['Code bodies', 'Context groups', 'Faster reads'])
        ->and($items[1]['published_at'])->toBe($items[1]['children'][0]['published_at'])
        ->and(Anchor::fromEncoded($items[1]['id'])?->toArray())->toBe(['axis' => 'context', 'hash' => 'release:v2.3']);
});

it('reads as before until groupBy() asks for a group', function () {
    $this->freezeTime();

    $items = release()->get()->toArray();

    expect(array_column($items, 'kind'))->toBe(['activity', 'activity', 'activity', 'activity', 'activity'])
        ->and(release()->groupBy()->get()->toArray())->toBe($items)
        ->and(release()->groupBy(Group::byContext())->get())->toHaveCount(3);
});

it('reads a member once, and an entry with no context or alone in its context solo', function () {
    $items = release()
        ->add(fn (Entry $entry) => $entry->by('Storyfeed')->action('ship', ['type' => 'change', 'label' => 'Docs'])->context(['type' => 'release', 'label' => 'v2.4'])->publishedAt(now()))
        ->groupBy(Group::byContext())
        ->get()
        ->toArray();

    $labels = collect($items)->flatMap(fn (array $item) => $item['kind'] === 'group'
        ? array_column(array_column($item['children'], 'object'), 'label')
        : [$item['object']['label']]);

    expect(array_column($items, 'kind'))->toBe(['activity', 'activity', 'group', 'activity'])
        ->and($items[0]['object']['label'])->toBe('Docs')
        ->and($labels->sort()->values()->all())->toBe(['Code bodies', 'Context groups', 'Docs', 'Faster reads', 'Goodbye', 'Hello'])
        ->and($labels->duplicates()->all())->toBe([]);
});

it('groups in kept order, at the first member\'s position, dateless entries too', function () {
    $album = ['type' => 'album', 'label' => 'Lisbon 2026'];

    $feed = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline('Opened the studio'))
        ->addMany(['Tram', 'Tiles', 'Harbour'], fn (string $photo, Entry $entry) => $entry
            ->by('Jasper')
            ->action('add', ['type' => 'photo', 'label' => $photo])
            ->context($album))
        ->add(fn (Entry $entry) => $entry->headline('Closed the studio'))
        ->add(fn (Entry $entry) => $entry->by('Jasper')->action('add', ['type' => 'photo', 'label' => 'Sunset'])->context($album))
        ->inOrder()
        ->groupBy(Group::byContext()->headline('Added :count photos to :context'));

    $items = $feed->get()->toArray();

    expect(array_column($items, 'kind'))->toBe(['activity', 'group', 'activity'])
        ->and($items[1]['count'])->toBe(4)
        ->and($items[1]['headline_template'])->toBe('Added :count photos to :context')
        ->and($items[1]['published_at'])->toBeNull()
        ->and(array_column(array_column($items[1]['children'], 'object'), 'label'))->toBe(['Tram', 'Tiles', 'Harbour', 'Sunset'])
        ->and($items[2]['headline_template'])->toBe('Closed the studio');

    // Paged by position, the group holds its place and is read once.
    $first = $feed->cursorPaginate(2);
    $rest = $feed->cursorPaginate(2, cursor: $first->nextCursor());

    expect(collect($first->items())->pluck('kind')->all())->toBe(['activity', 'group'])
        ->and(collect($rest->items())->pluck('headline_template')->all())->toBe(['Closed the studio'])
        ->and($rest->nextCursor())->toBeNull();
});

it('places a dateless group among the dateless entries of a dated feed, in the order given', function () {
    $guide = ['type' => 'guide', 'label' => 'Billing'];

    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->headline('First'))
        ->addMany(['Invoices', 'Refunds'], fn (string $page, Entry $entry) => $entry->action('update', ['type' => 'page', 'label' => $page])->context($guide))
        ->add(fn (Entry $entry) => $entry->headline('Last'))
        ->add(fn (Entry $entry) => $entry->headline('Dated')->publishedAt(now()->subDay()))
        ->groupBy(Group::byContext())
        ->get()
        ->toArray();

    expect(array_column($items, 'kind'))->toBe(['activity', 'activity', 'group', 'activity'])
        ->and($items[0]['headline_template'])->toBe('Dated')
        ->and($items[1]['headline_template'])->toBe('First')
        ->and(array_column(array_column($items[2]['children'], 'object'), 'label'))->toBe(['Invoices', 'Refunds'])
        ->and($items[3]['headline_template'])->toBe('Last');
});

it('filters members as it filters entries, and leaves out a group none are left in', function () {
    $feed = release()
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->action('tag', ['type' => 'change', 'label' => 'v2.3 tag'])->context(['type' => 'release', 'label' => 'v2.3'])->publishedAt(now()->subDays(2)))
        ->groupBy(Group::byContext());

    $shipped = (clone $feed)->only('ship')->get()->toArray();
    $tagged = (clone $feed)->actor('Tey Labs')->get()->toArray();
    $posts = (clone $feed)->only('post')->get()->toArray();

    expect(array_column($shipped, 'kind'))->toBe(['group'])
        ->and($shipped[0]['count'])->toBe(3)
        // Narrowed to one member, the group reads as that activity.
        ->and(array_column($tagged, 'kind'))->toBe(['activity', 'activity', 'activity'])
        ->and(array_column(array_column($tagged, 'object'), 'label'))->toBe(['Goodbye', 'v2.3 tag', 'Hello'])
        ->and(array_column($posts, 'kind'))->toBe(['activity', 'activity'])
        ->and((clone $feed)->limit(1)->get()->toArray()[0]['object']['label'])->toBe('Goodbye');
});

it('reads a group\'s members past its children from the composed entries', function () {
    config()->set('storyfeed.grouping.children_limit', 2);

    $feed = release()->groupBy(Group::byContext());
    $group = $feed->get()->toArray()[1];

    $first = $feed->members($group['id'], 2);
    $rest = $feed->members($group['id'], 2, cursor: $first->nextCursor());

    expect($group['children'])->toHaveCount(2)
        ->and($group['children_truncated'])->toBeTrue()
        ->and(collect($first->items())->pluck('object.label')->all())->toBe(['Code bodies', 'Context groups'])
        ->and(collect($rest->items())->pluck('object.label')->all())->toBe(['Faster reads'])
        ->and($rest->nextCursor())->toBeNull()
        ->and((clone $feed)->only('post')->members($group['id'])->items())->toBe([]);
});

it('still reads no other group\'s members from a composed feed', function () {
    expect(fn () => release()->members((new Anchor('context', 'release:v2.3'))->encode()))
        ->toThrow(FeedMisconfigured::class, 'cannot read a group\'s members')
        ->and(fn () => release()->groupBy(Group::byContext())->members((new Anchor('repeat', 'x'))->encode()))
        ->toThrow(FeedMisconfigured::class, 'cannot read a group\'s members');
});

it('groups by context only, with a headline the context axis can keep', function () {
    expect(fn () => Storyfeed::compose()->groupBy(Group::byActors()))
        ->toThrow(InvalidArgumentException::class, 'A composed feed groups entries by their shared context, with Group::byContext(). [actors] groups are inferred')
        ->and(fn () => Storyfeed::compose()->groupBy(Group::byContext()->headline(':actor shipped :context')))
        ->toThrow(StoryMisconfigured::class, 'uses [:actor] in its [context] group headline')
        ->and(Storyfeed::compose()->groupBy(Group::byContext()->headline(':actors shipped :count changes to :context')))
        ->toBeInstanceOf(ComposedFeed::class);
});
