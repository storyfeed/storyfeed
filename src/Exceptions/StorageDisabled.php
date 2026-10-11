<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown when an app that declared `Storyfeed::withoutStorage()` records.
 *
 * Such an app composes and renders feeds from its own data and has none of
 * core's tables, so a recording call would otherwise end in a SQL error about
 * a missing `feed_*` table, far from the declaration that explains it.
 */
class StorageDisabled extends LogicException
{
    public static function recording(): self
    {
        return new self(
            'This app uses Storyfeed to compose and render only (Storyfeed::withoutStorage()), so it cannot '
            .'record activities. Compose the feed with Storyfeed::compose(), or remove withoutStorage() '
            .'and run the migrations to record.'
        );
    }
}
