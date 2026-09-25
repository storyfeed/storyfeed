<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\ActivityRoles;

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
     * The object type the registries are asked about, as SQL to select and
     * group by, with the join it needs added to `$query`.
     *
     * A deleted model's activities are stored against the tombstone, but the
     * read path asks grammar, icons and tombstone rules about the deleted
     * model's own alias (NodePresenter::objectType()), and so does prune. A
     * check that grouped by the stored `object_type` reported
     * `storyfeed.tombstone.create` as missing grammar, an error, while the
     * feed rendered `order.create`'s headline (found on a production doctor).
     *
     * `$activities` names the activities table where the query aliases it.
     *
     * @param  ActivityBuilder<Activity>|QueryBuilder  $query
     */
    protected function objectTypeOf(ActivityBuilder|QueryBuilder $query, ?string $activities = null): string
    {
        $activities ??= $this->activities()->getModel()->getTable();
        $grammar = $query instanceof QueryBuilder ? $query->getGrammar() : $query->getQuery()->getGrammar();

        if (! TombstoneEntity::installed()) {
            return $grammar->wrap("{$activities}.object_type");
        }

        $tombstone = new (config('storyfeed.models.tombstone', FeedTombstone::class));

        $query->leftJoin("{$tombstone->getTable()} as former_objects", function (JoinClause $join) use ($activities, $tombstone) {
            $join->on('former_objects.'.$tombstone->getKeyName(), '=', "{$activities}.object_id")
                ->where("{$activities}.object_type", '=', $tombstone->getMorphClass());
        });

        return 'coalesce('.$grammar->wrap('former_objects.model_type').', '.$grammar->wrap("{$activities}.object_type").')';
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
     * @param  value-of<ActivityRoles::STORED>|null  $role
     * @return list<string>
     */
    protected function recordedAliases(?string $role = null): array
    {
        $aliases = [];

        foreach ($role === null ? ActivityRoles::STORED : [$role] as $each) {
            $aliases = [
                ...$aliases,
                ...$this->activities()->distinct()->toBase()->pluck("{$each}_type")->filter()->all(),
            ];
        }

        return array_values(array_unique($aliases));
    }

    /**
     * The alias a model class stores under, or null when it cannot store at all.
     *
     * NULL, NOT A THROW. Under `Relation::enforceMorphMap()` a model missing
     * from the map throws on getMorphClass(), and one such class used to take
     * a whole check down with it: an app with a probe subclass that is aliased
     * only while it runs (`ScaleProject extends Project`) got `surface` and
     * `hydration` reported as failed, on every run, telling it nothing about
     * any of its other models. The class is a fact to report, once, by the
     * check that owns it (`surface.unaliased`) — not a reason to stop looking.
     *
     * @param  class-string<Model>  $class
     */
    protected function aliasFor(string $class): ?string
    {
        try {
            return (new $class)->getMorphClass();
        } catch (ClassMorphViolationException) {
            return null;
        }
    }

    protected function lengthExpression(string $column): string
    {
        return match (Schema::getConnection()->getDriverName()) {
            'sqlsrv' => "len({$column})",
            default => "length({$column})",
        };
    }
}
