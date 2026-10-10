<?php

namespace Storyfeed\Sources;

use Storyfeed\Contracts\FeedSource;

/**
 * Static content as a feed: items given up front, never stored.
 *
 *   // config/storyfeed.php
 *   'sources' => [
 *       'changelog' => ['driver' => 'array', 'items' => [
 *           ['verb' => 'release', 'actor' => 'Storyfeed', 'object' => ['type' => 'release', 'label' => 'v0.18.0', 'url' => '…'], 'published_at' => '2026-10-20'],
 *       ]],
 *   ],
 *
 *   Storyfeed::feed()->source(new ArraySource($items))->log()->get();
 *
 * `'in_order' => true` (or `inOrder: true`) keeps the items in the order
 * given, as a composed feed's inOrder() does: nothing groups, and cursors
 * page by position.
 *
 * Each item is an array or a Entry; see Entry for the keys.
 */
final class ArraySource implements FeedSource
{
    /**
     * @param  iterable<Entry|array<string, mixed>>  $items
     * @param  bool  $inOrder  keep the items in the order given instead of newest first, as a composed feed's inOrder() does
     */
    public function __construct(
        protected iterable $items = [],
        public readonly bool $inOrder = false,
    ) {}

    public function items(): iterable
    {
        return $this->items;
    }
}
