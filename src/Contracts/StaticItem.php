<?php

namespace Storyfeed\Contracts;

use Storyfeed\Sources\Entry;

/**
 * One static feed item as a class, discovered from a directory by the
 * `classes` source the way Pennant discovers feature classes.
 *
 *   class ReleaseV018 implements StaticItem
 *   {
 *       public function entry(): Entry
 *       {
 *           return Entry::make('release', '2026-09-20 09:00', actor: 'Storyfeed', object: [...]);
 *       }
 *   }
 *
 * The class is resolved from the container, so its constructor may type-hint
 * dependencies. It returns what an `array` source item is: an Entry or an array.
 */
interface StaticItem
{
    /** @return Entry|array<string, mixed> */
    public function entry(): Entry|array;
}
