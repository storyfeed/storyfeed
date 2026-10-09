<?php

namespace Storyfeed\Sources;

use Storyfeed\Contracts\FeedSource;
use Storyfeed\Models\Activity;
use Storyfeed\Support\ActivityRoles;

/**
 * The stored feed, and the default source. A feed reading it never calls
 * items(): it reads through SQL, with everything stored history supports.
 * items() is every published activity, for code that wants them as a source.
 */
final class DatabaseSource implements FeedSource
{
    public function items(): iterable
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query()->published()->with(ActivityRoles::cachedRelations())->lazyById();
    }
}
