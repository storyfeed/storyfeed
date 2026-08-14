<?php

namespace Storyfeed\Models\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Storyfeed\Actions\SyncParticipants;

/**
 * @template TModel of \Storyfeed\Models\Activity
 *
 * @extends Builder<TModel>
 */
class ActivityBuilder extends Builder
{
    /**
     * Activities visible to readers. The reader passes its own $now so the
     * gate cannot shift between the phases of one read.
     */
    public function published(?DateTimeInterface $now = null): static
    {
        $this->whereNotNull('published_at')
            ->where('published_at', '<=', $now ?? now());

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
            foreach (['actor', 'object', 'target', 'context'] as $role) {
                $query->orWhere(function (self $q) use ($role): void {
                    $q->whereNotNull("{$role}_type")->whereNull("cached_{$role}_id");
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

    /**
     * Activities involving the model in ANY role — actor, object, target or
     * context. This is what an entity's own feed means: everything that
     * mentions it, however it participated.
     *
     * A semi-join against feed_participants, not an OR across the four morph
     * pairs. The OR is the shape every earlier generation of this feed used,
     * and it cannot be indexed: each branch would need its own composite and
     * the planner still could not use them for the newest-first ordering.
     * SyncParticipants maintains the rows; `storyfeed:participants` backfills
     * an install that predates the table.
     */
    public function involving(Model $model): static
    {
        $participants = SyncParticipants::table();
        $activities = $this->getModel()->getTable();
        $alias = $model->getMorphClass();
        $key = (string) $model->getKey();

        $this->whereExists(function (QueryBuilder $query) use ($participants, $activities, $alias, $key) {
            $query->from($participants)
                ->whereColumn("{$participants}.activity_id", "{$activities}.id")
                ->where("{$participants}.entity_type", $alias)
                ->where("{$participants}.entity_id", $key);
        });

        return $this;
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
            now()->startOfWeek(),
            now()->endOfWeek(),
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
            ->where("{$role}_id", $model->getKey());

        return $this;
    }
}
