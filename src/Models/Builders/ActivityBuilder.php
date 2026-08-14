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
     * A semi-join against feed_participants, driven FROM the participants
     * index — the direction matters more than the join does.
     *
     * The first cut of this was a correlated EXISTS. That reads naturally and
     * is 100-2000x slower — not because the index goes unused, but because it
     * cannot DRIVE. Correlating on activity_id makes feed_activities the outer
     * loop, walked in published_at order, and demotes the participants index
     * to a per-row probe that answers "is this one a match?" rather than
     * "which ones are?". Cost then scales with how DEEP the scan must go,
     * which is worst for the entity with the FEWEST activities — a quiet
     * project pages through the whole table. At 400k rows that was 141ms for a
     * two-activity entity against 0.05ms here.
     *
     * (Which participants index serves the probe is planner discretion and
     * irrelevant: this schema picked the activity_id unique, a smaller dataset
     * picked the entity index. Both are probes. An earlier draft of this note
     * claimed the entity index "cannot be used at all" — false, and caught by
     * the consumer reading their own EXPLAIN against this same commit.)
     *
     * Binding entity_type and entity_id instead lets the index narrow AND
     * order, which is what it was added for. SQLite measurements; the driving
     * direction is structural, the ratios are not.
     *
     * Not an OR across the four morph pairs, which is the shape every earlier
     * generation of this feed used. Each branch IS individually indexed, so
     * the OR is not unindexable — measured, it is fine on SQLite, which
     * resolves it as a multi-index OR plus a sort. It is avoided because that
     * plan is a planner favour rather than a guarantee: MySQL's index_merge
     * is famously reluctant across four branches, and the union still cannot
     * satisfy the newest-first ordering without a temp sort of every match.
     * One index that already carries the order beats four that don't.
     *
     * SyncParticipants maintains the rows; `storyfeed:participants` backfills
     * an install that predates the table.
     */
    public function involving(Model $model): static
    {
        $participants = SyncParticipants::table();
        $activities = $this->getModel()->getTable();
        $alias = $model->getMorphClass();
        $key = (string) $model->getKey();

        // Deliberately NOT limited/ordered inside: the candidate set has to stay
        // whole so verb filters, date ranges and curation still see everything
        // that qualifies. Ordering happens on the outer query.
        $this->whereIn("{$activities}.id", function (QueryBuilder $query) use ($participants, $alias, $key) {
            $query->from($participants)
                ->select('activity_id')
                ->where('entity_type', $alias)
                ->where('entity_id', $key);
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
