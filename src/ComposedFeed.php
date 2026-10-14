<?php

namespace Storyfeed;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Storyfeed\Contracts\FeedSource;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Grouping\Anchor;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\Entry;
use Storyfeed\Sources\SourceRead;

/**
 * A feed composed by hand rather than read from what was recorded: any
 * feed-shaped data an app already has (a project showcase, a record's status
 * progression, talks), drawn by the same kits and never stored.
 *
 *   Storyfeed::compose()
 *       ->add(fn (Entry $entry) => $entry->by($customer)->action('place', $order)->publishedAt($order->placed_at))
 *       ->add(fn (Entry $entry) => $entry->by($kitchen)->action('confirm', $order)->publishedAt($order->confirmed_at))
 *       ->inOrder()
 *       ->get();
 *
 * The terminals are a feed's: get(), cursorPaginate() and simplePaginate()
 * return what any read returns, ready for a kit's `page` prop. Role filters,
 * only()/except(), limits and cursors work as they do on a source.
 *
 * It needs none of the stored feed: no tables, snapshots, cache or queue.
 * Model roles are read through their toFeed() as the feed is read.
 *
 * What you add is what you get. A composed feed reads as Log unless it asks
 * for live(), whatever `grouping.default` says, so an entry's own headline is
 * never folded into a group's. Entries dated in the future are shown, since
 * the app chose them. Live never groups a kept order (inOrder()) or a
 * dateless entry.
 *
 * Entries that share a context group when the feed asks, kept order and
 * dateless entries included: one release's changes as "v2.3 shipped 6
 * changes", expanding to the changes.
 *
 *   Storyfeed::compose()
 *       ->addMany($release->changes, fn (Change $change, Entry $entry) => $entry
 *           ->action('ship', $change)
 *           ->context($release)
 *           ->publishedAt($release->published_at))
 *       ->groupBy(Group::byContext()->headline(':context shipped :count changes'))
 *       ->get();
 */
class ComposedFeed extends FeedBuilder
{
    /** @var list<Entry> */
    protected array $entries = [];

    protected bool $ordered = false;

    /** @var array<string, Group> the groups asked for, keyed by axis */
    protected array $groups = [];

    /** The context whose members alone a members() read reads. */
    protected ?string $memberOf = null;

    public function __construct()
    {
        $this->sourceName = 'compose';
        $this->source = new ArraySource([]);
    }

    /**
     * Add an entry, built by the closure or given whole.
     *
     *   ->add(fn (Entry $entry) => $entry->headline('Opened the doors to the new kitchen'))
     *
     * @param  (Closure(Entry): mixed)|Entry  $entry
     */
    public function add(Closure|Entry $entry): static
    {
        if ($entry instanceof Closure) {
            $entry($built = new Entry);
            $entry = $built;
        }

        $this->entries[] = $entry->validated();
        $this->source = new ArraySource($this->entries, $this->ordered);

        return $this;
    }

    /**
     * Add an entry for each item, built by the closure, as `createMany()`
     * creates a model for each:
     *
     *   ->addMany($projects, fn (Project $project, Entry $entry) => $entry->headline(':object', ['object' => $project]))
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  iterable<TKey, TValue>  $items
     * @param  Closure(TValue, Entry, TKey): mixed  $callback
     */
    public function addMany(iterable $items, Closure $callback): static
    {
        foreach ($items as $key => $item) {
            $this->add(fn (Entry $entry) => $callback($item, $entry, $key));
        }

        return $this;
    }

    /**
     * Keep the entries in the order they were added, instead of newest
     * first: a showcase in prominence order, a progression oldest first,
     * upcoming talks then past ones. Cursors page by position, and nothing
     * groups unless groupBy() asks.
     */
    public function inOrder(): static
    {
        $this->ordered = true;
        $this->source = new ArraySource($this->entries, true);

        return $this;
    }

    /**
     * Fold the entries that name one context into one group node:
     *
     *   ->groupBy(Group::byContext()->headline(':context shipped :count changes'))
     *
     * The group stands where its first member does in kept order, and at
     * its newest member's time otherwise. An entry with no context, or the
     * only one naming its context, reads solo. A group is always a shared
     * context: Live infers the other axes, which live() reads.
     */
    public function groupBy(Group ...$groups): static
    {
        foreach ($groups as $group) {
            if ($group->axis !== 'context') {
                throw new InvalidArgumentException(
                    "A composed feed groups entries by their shared context, with Group::byContext(). [{$group->axis}] groups are inferred: read them with live().",
                );
            }

            // The tokens the axis pins, as a Story's group headline is held to them.
            $storyfeed = app(StoryfeedManager::class);
            $allowed = $storyfeed->aggregateTokens($group->axis)
                ?? throw StoryMisconfigured::unknownAxis('Storyfeed::compose()', $group->axis, array_keys($storyfeed->registeredAxes()));

            if (is_string($template = $group->template())) {
                preg_match_all('/:[a-z]+/', $template, $matches);

                foreach (array_diff(array_unique($matches[0]), $allowed) as $token) {
                    throw StoryMisconfigured::unpinnedToken('Storyfeed::compose()', $group->axis, $token, $allowed);
                }
            }

            $this->groups[$group->axis] = $group;
        }

        return $this;
    }

    /**
     * A context group's members past its `children`, read from the entries
     * added, as members() reads a stored group: through this feed's filters,
     * in the order the group's children are in. Compose the same feed to
     * answer the request, as a composed feed's cursor is answered.
     */
    public function members(Anchor|string $group, ?int $perPage = null, string $cursorName = 'cursor', Cursor|string|null $cursor = null): FeedPaginator
    {
        $anchor = $group instanceof Anchor ? $group : Anchor::fromEncoded($group);

        if ($anchor === null || $anchor->parameter('axis') !== 'context' || ! isset($this->groups['context'])) {
            return parent::members($group, $perPage, $cursorName, $cursor);
        }

        if (($perPage ?? $this->limit) < 1) {
            throw new InvalidArgumentException('The number of group members per page must be at least 1.');
        }

        $members = clone $this;
        $members->groups = [];
        $members->memberOf = $anchor->parameter('hash');
        $members->mode = 'log';

        return $members->cursorPaginate($perPage, $cursorName, $cursor);
    }

    /** A composed feed reads its own entries. */
    public function source(string|FeedSource $source): never
    {
        throw new LogicException('A composed feed reads the entries added to it, not a source. Read a source with Storyfeed::feed()->source().');
    }

    protected function mode(): string
    {
        return $this->mode === null ? 'log' : parent::mode();
    }

    protected function hidesScheduled(): bool
    {
        return false;
    }

    protected function groupsByContext(): bool
    {
        return isset($this->groups['context']);
    }

    protected function admitsActivity(Carbon $now): Closure
    {
        $admits = parent::admitsActivity($now);

        if ($this->memberOf === null) {
            return $admits;
        }

        return fn (Activity $activity): bool => SourceRead::context($activity) === $this->memberOf && $admits($activity);
    }

    protected function presenter(): NodePresenter
    {
        return $this->groups === [] ? parent::presenter() : parent::presenter()->withGroups($this->groups);
    }
}
