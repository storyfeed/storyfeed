<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * How a feed read finds the activities involving one entity.
 *
 * A query that walks the timeline (the window floor, the group aggregate,
 * the solo stream, a Log page) JOINS feed_participants and orders, pages and
 * gates on the participant row's own (published_at, activity_id): the entity
 * index (entity_type, entity_id, published_at, activity_id) hands over the
 * entity's activities newest first, so a page costs the rows it shows
 * whatever share of history the entity is in.
 *
 * Both alternatives cost in proportion to that share. An IN subquery
 * materializes and sorts every match in every query of a page: a project in
 * 11% of a million activities read Live in 615ms on SQLite. Walking
 * feed_activities newest first and probing each row costs per row walked,
 * worst for an entity in about 1% of history: at three million activities a
 * document's Live page took 430ms on PostgreSQL and 545ms on MariaDB, and a
 * window floor that took 244ms that way took 6ms from the ordered index (#52).
 *
 * A BOUNDED query, one already narrowed by a page's group hashes or ids,
 * probes the participants primary key per row instead: it checks a few rows
 * and has no order to borrow. The probe is a scalar subquery, not EXISTS,
 * because MySQL and MariaDB flatten EXISTS into the same semi-join as IN.
 * PostgreSQL keeps IN, and runs a correlated scalar probe several times
 * slower.
 *
 * Reading order from the participant row is only right while its copy of
 * published_at matches the activity's: Activity re-stamps its rows whenever
 * published_at changes, and the doctor's `participants` check reports drift.
 *
 * @internal
 */
final class InvolvingLookup
{
    public function __construct(
        protected readonly string $type,
        protected readonly string $id,
        protected readonly bool $deep,
    ) {}

    public static function for(Model $model, bool $deep = true): self
    {
        return new self($model->getMorphClass(), (string) $model->getKey(), $deep);
    }

    /**
     * The (published_at, id) a timeline query orders, pages and gates on.
     *
     * @return array{string, string}
     */
    public static function timeline(): array
    {
        return ['involved.involved_at', 'involved.involved_id'];
    }

    /**
     * @param  ActivityBuilder<covariant Activity>  $activities
     * @param  bool  $bounded  something else (group hashes, ids) already
     *                         narrows the query to a few rows
     */
    public function apply(ActivityBuilder $activities, string $now, bool $bounded = false): void
    {
        $key = $activities->getModel()->getQualifiedKeyName();
        $participants = SyncParticipants::table();

        if (! $bounded) {
            // Joined as a derived table with names of its own, so a query()
            // callback's unqualified `id` or `published_at` stays unambiguous.
            // Every planner merges it back into a join on the entity index.
            // The participants key (activity_id, entity_type, entity_id) means
            // the join cannot repeat an activity.
            $involved = $activities->getQuery()->newQuery()
                ->from($participants)
                ->select(["{$participants}.activity_id as involved_id", "{$participants}.published_at as involved_at"])
                ->where("{$participants}.entity_type", $this->type)
                ->where("{$participants}.entity_id", $this->id)
                ->when(! $this->deep, fn (QueryBuilder $query) => $query->where("{$participants}.distance", 0));
            $connection = $activities->getModel()->getConnection();
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                // STRAIGHT_JOIN reads participants first. Left to choose,
                // MySQL 8.4 scanned all of feed_activities and sorted for an
                // entity in every activity: 2.5s against 1ms.
                $grammar = $connection->getQueryGrammar();
                $activities->fromRaw(
                    '('.$involved->toSql().') as '.$grammar->wrapTable('involved')
                    .' straight_join '.$grammar->wrapTable($activities->getModel()->getTable())
                    .' on '.$grammar->wrap('involved.involved_id').' = '.$grammar->wrap($key),
                    $involved->getBindings(),
                );
            } else {
                $activities->joinSub($involved, 'involved', 'involved.involved_id', '=', $key);
            }
            $activities
                // The publish gate again, on the copy the index carries.
                ->whereNotNull('involved.involved_at')
                ->where('involved.involved_at', '<=', $now);
            if ($activities->getQuery()->columns === null) {
                $activities->select($activities->getModel()->getTable().'.*');
            }

            return;
        }

        $matches = $activities->getQuery()->newQuery()
            ->from($participants)
            ->where("{$participants}.entity_type", $this->type)
            ->where("{$participants}.entity_id", $this->id)
            ->when(! $this->deep, fn (QueryBuilder $query) => $query->where("{$participants}.distance", 0));

        if ($activities->getModel()->getConnection()->getDriverName() === 'pgsql') {
            $activities->whereIn($key, $matches->select("{$participants}.activity_id"));

            return;
        }

        $matches->selectRaw('1')->whereColumn("{$participants}.activity_id", $key)->limit(1);
        $activities->whereRaw('('.$matches->toSql().') is not null', $matches->getBindings());
    }
}
