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
 * Each item is an array or a SourceItem; see SourceItem for the keys.
 */
final class ArraySource implements FeedSource
{
    /** @param  iterable<SourceItem|array<string, mixed>>  $items */
    public function __construct(
        protected iterable $items = [],
    ) {}

    public function items(): iterable
    {
        return $this->items;
    }
}
