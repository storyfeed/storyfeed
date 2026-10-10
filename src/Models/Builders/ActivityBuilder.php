<?php

namespace Storyfeed\Models\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Concerns\FiltersRoleTypes;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\InlineEntity;
use Storyfeed\Support\InvolvingLookup;
use Storyfeed\Support\MorphKeyType;
use Storyfeed\Support\RoleTypes;

/**
 * @template TModel of \Storyfeed\Models\Activity
 *
 * @extends Builder<TModel>
 */
class ActivityBuilder extends Builder
{
    use FiltersRoleTypes;

    /** @param  Model|string|list<Model|string>  $types */
    protected function whereRoleTypes(string $role, Model|string|array $types): static
    {
        $this->whereIn($this->qualifyColumn($role.'_type'), RoleTypes::resolve($role, $types));

        return $this;
    }

    /**
     * Activities visible to readers. The reader passes its own $now so the
     * gate cannot shift between the phases of one read.
     */
    public function published(?DateTimeInterface $now = null): static
    {
        // Bound through the column's own format: a bare Carbon would be
        // formatted by the grammar at whole seconds and hide a row published
        // `.400000` into the current second until the second turned over.
        $this->whereNotNull('published_at')
            ->where('published_at', '<=', Chronology::stamp($now ?? now()));

        return $this;
    }

    /**
     * Activities with at least one filled role that has no snapshot yet —
     * the trickle's work queue, and the "snapshot backlog" number an ops
     * dashboard wants. Public so consumers never copy this query.
     */
    public function uncached(): static
    {
        $this->where(function (self $query) {
            foreach (ActivityRoles::STORED as $role) {
                $query->orWhere(function (self $q) use ($role): void {
                    // A role with no model behind it never gains a snapshot.
                    InlineEntity::exclude($q->whereNotNull("{$role}_type")->whereNull("cached_{$role}_id"), $this->getModel(), $role);
                });
            }
        });

        return $this;
    }

    public function verb(string $verb): static
    {
        $this->where('verb', $verb);

        return $this;
    }

    public function actor(Model $model): static
    {
        return $this->whereMorphRole('actor', $model);
    }

    public function object(Model $model): static
    {
        return $this->whereMorphRole('object', $model);
    }

    public function target(Model $model): static
    {
        return $this->whereMorphRole('target', $model);
    }

    public function context(Model $model): static
    {
        return $this->whereMorphRole('context', $model);
    }

    public function origin(Model $model): static
    {
        return $this->whereMorphRole('origin', $model);
    }

    public function result(Model $model): static
    {
        return $this->whereMorphRole('result', $model);
    }

    public function instrument(Model $model): static
    {
        return $this->whereMorphRole('instrument', $model);
    }

    /**
     * Activities involving the model in any direct role or through distant
     * relations. Pass `deep: false` to match direct participation only.
     *
     * Read through feed_participants, never as an OR across the role morph
     * pairs, which is the shape every earlier generation of this feed used.
     * Each branch of that OR is indexed, but the union cannot satisfy the
     * newest-first ordering without sorting every match, and MySQL's
     * index_merge is reluctant across four branches.
     *
     * Which way the lookup runs, from participants or from the timeline,
     * depends on how many activities involve the model: see InvolvingLookup.
     * The direction matters more than the join does. A correlated probe
     * driven from the timeline is what a busy project wants and what a quiet
     * one cannot afford: it pages through the whole table to find two rows.
     *
     * The candidate set stays whole, never limited or ordered here, so verb
     * filters, date ranges and curation still see everything that qualifies.
     *
     * SyncParticipants maintains the rows; `storyfeed:participants` backfills
     * an install that predates the table.
     */
    public function involving(Model $model, bool $deep = true): static
    {
        InvolvingLookup::for($this, $model, $deep)->apply($this);

        return $this;
    }

    /**
     * Activities involving any entity of these types, including recorded
     * ancestors. Direct-only reads keep participants with distance zero.
     *
     * @param  Model|string|list<Model|string>  $types
     */
    public function involvingType(Model|string|array $types, bool $deep = true): static
    {
        $participants = SyncParticipants::table();
        $activities = $this->getModel()->getTable();
        $resolved = RoleTypes::resolve('involving', $types);

        $this->whereIn("{$activities}.id", function (QueryBuilder $query) use ($participants, $resolved, $deep) {
            $query->from($participants)
                ->select('activity_id')
                ->whereIn('entity_type', $resolved)
                ->when(! $deep, fn (QueryBuilder $query) => $query->where('distance', 0));
        });

        return $this;
    }

    /** Every activity that mentions the entity directly, in any role. */
    public function involvingDirectly(Model $model): static
    {
        return $this->involving($model, deep: false);
    }

    public function today(): static
    {
        $this->whereDate('published_at', today());

        return $this;
    }

    public function yesterday(): static
    {
        $this->whereDate('published_at', today()->subDay());

        return $this;
    }

    public function thisWeek(): static
    {
        $this->whereBetween('published_at', [
            Chronology::stamp(now()->startOfWeek()),
            Chronology::stamp(now()->endOfWeek()),
        ]);

        return $this;
    }

    /**
     * Role columns store morph aliases, so the comparison must use
     * getMorphClass() — never get_class().
     */
    protected function whereMorphRole(string $role, Model $model): static
    {
        $this->where("{$role}_type", $model->getMorphClass())
            ->where("{$role}_id", MorphKeyType::value($model->getKey()));

        return $this;
    }
}
