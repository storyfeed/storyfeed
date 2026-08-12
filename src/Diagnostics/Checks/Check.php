<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;

/**
 * Shared plumbing for the checks — the configured-model queries and the
 * table guards. Every check resolves its model from config rather than
 * importing the default, because an app may swap any of them.
 */
abstract class Check implements DiagnosticCheck
{
    protected function table(string $key): string
    {
        return config("storyfeed.tables.{$key}", "feed_{$key}");
    }

    protected function hasTable(string $key): bool
    {
        return Schema::hasTable($this->table($key));
    }

    /** @return ActivityBuilder<Activity> */
    protected function activities(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }

    /** @return Builder<Grouping> */
    protected function groupings(): Builder
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query();
    }

    protected function lengthExpression(string $column): string
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlsrv' => "len({$column})",
            default => "length({$column})",
        };
    }
}
