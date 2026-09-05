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

    /**
     * Morph aliases that fill ANY role on any activity, or one role when named.
     *
     * Shared because three checks reason about "what appears in the feed" and
     * must agree on what that means: a model used only as an actor or a target
     * — a User, a Customer — is wired into the feed exactly as much as one used
     * as the object. Checking `object_type` alone would report every one of
     * them as unwired, which is the noise that gets a report ignored, and it
     * is a nastier mistake than missing a real gap.
     *
     * @param  'actor'|'object'|'target'|'context'|null  $role
     * @return list<string>
     */
    protected function recordedAliases(?string $role = null): array
    {
        $aliases = [];

        foreach ($role === null ? ['actor', 'object', 'target', 'context'] : [$role] as $each) {
            $aliases = [
                ...$aliases,
                ...$this->activities()->distinct()->toBase()->pluck("{$each}_type")->filter()->all(),
            ];
        }

        return array_values(array_unique($aliases));
    }

    protected function lengthExpression(string $column): string
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlsrv' => "len({$column})",
            default => "length({$column})",
        };
    }
}
