<?php

namespace Storyfeed;

use Closure;
use LogicException;
use Storyfeed\Contracts\FeedSource;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\Entry;

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
 * the app chose them. Kept order (inOrder()) never groups, and a dateless
 * entry never does either.
 */
class ComposedFeed extends FeedBuilder
{
    /** @var list<Entry> */
    protected array $entries = [];

    protected bool $ordered = false;

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
     * groups.
     */
    public function inOrder(): static
    {
        $this->ordered = true;
        $this->source = new ArraySource($this->entries, true);

        return $this;
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
}
