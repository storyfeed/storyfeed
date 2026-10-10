<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * How one read finds the activities involving one entity: decided once per
 * read from how many there are, because no single SQL shape is fast for
 * both a quiet entity and a busy one.
 *
 * A QUIET entity is read FROM feed_participants: the entity index yields its
 * few activities and only those are sorted. PostgreSQL gets them as a
 * literal id list, because it estimates (entity_type, entity_id) from
 * independent column statistics, 41k rows for an 8k-activity document and
 * 2k for a five-activity one, and on that estimate walks the whole timeline
 * probing every row: 1.8s at a million activities for five results. The
 * other engines estimate an equality on the index prefix well and keep the
 * IN subquery. MariaDB turns a literal list of more than 1,000 values into
 * a table value constructor and then misplans the rest of the query.
 *
 * A BUSY entity is read FROM the timeline: walk feed_activities newest first
 * and probe the participants identity (activity_id, entity_type, entity_id)
 * per row. At 11% of history a page is found within a few hundred rows. The
 * IN subquery instead materializes and sorts every match, in every query of
 * a Live page: a project feed at one million activities took 615ms on
 * SQLite and 655ms on MariaDB (#52). The probe is a scalar subquery rather
 * than EXISTS because MySQL and MariaDB flatten EXISTS into the same
 * semi-join as IN and pick the same participants-first plan. PostgreSQL
 * keeps the IN form: once an entity is dense its planner chooses this walk
 * itself, and it runs a correlated scalar probe several times slower.
 *
 * A BOUNDED query, one already narrowed by a page's group hashes or ids,
 * takes the probe either way: it checks a few rows, and an id list there
 * costs MySQL a range analysis per query, ~70ms each at 8k ids.
 *
 * The cut-over is where the two costs cross. Reading from participants costs
 * per match; reading from the timeline costs per row walked, about
 * history/matches per result. Measured on SQLite at a million activities they
 * meet near 10,000 matches, which is 10·√history, so the threshold grows
 * with the table: about 3,200 at 100k activities and 17,000 at three million.
 *
 * @internal
 */
final class InvolvingLookup
{
    protected static ?int $threshold = null;

    /** @param  list<int>|null  $ids  the matching activity ids, when quiet */
    protected function __construct(
        protected readonly string $type,
        protected readonly string $id,
        protected readonly bool $deep,
        protected readonly ?array $ids,
    ) {}

    /** @param  ActivityBuilder<covariant Activity>  $activities */
    public static function for(ActivityBuilder $activities, Model $model, bool $deep = true): self
    {
        $type = $model->getMorphClass();
        $id = (string) $model->getKey();
        $query = $activities->getQuery()->newQuery();
        $limit = self::$threshold ?? self::threshold($query, $activities->getModel());

        $ids = $query->newQuery()
            ->from(SyncParticipants::table())
            ->where('entity_type', $type)
            ->where('entity_id', $id)
            ->when(! $deep, fn (QueryBuilder $query) => $query->where('distance', 0))
            ->limit($limit + 1)
            ->pluck('activity_id')
            ->map(fn (mixed $key): int => (int) $key)
            ->all();

        return new self($type, $id, $deep, count($ids) > $limit ? null : $ids);
    }

    /**
     * @param  ActivityBuilder<covariant Activity>  $activities
     * @param  bool  $bounded  the query is already narrowed to a few rows by
     *                         something else (a page's group hashes, a list
     *                         of ids), so it only needs a per-row check
     */
    public function apply(ActivityBuilder $activities, bool $bounded = false): void
    {
        $key = $activities->getModel()->getQualifiedKeyName();
        $participants = SyncParticipants::table();
        $matches = $activities->getQuery()->newQuery()
            ->from($participants)
            ->where("{$participants}.entity_type", $this->type)
            ->where("{$participants}.entity_id", $this->id)
            ->when(! $this->deep, fn (QueryBuilder $query) => $query->where("{$participants}.distance", 0));
        $pgsql = $activities->getModel()->getConnection()->getDriverName() === 'pgsql';

        if ($this->ids !== null && ! $bounded && $pgsql) {
            // Integers inlined, not bound: the list can run to the threshold.
            $activities->whereIntegerInRaw($key, $this->ids);
        } elseif ($pgsql || ($this->ids !== null && ! $bounded)) {
            $activities->whereIn($key, $matches->select("{$participants}.activity_id"));
        } else {
            $matches->selectRaw('1')->whereColumn("{$participants}.activity_id", $key)->limit(1);
            $activities->whereRaw('('.$matches->toSql().') is not null', $matches->getBindings());
        }
    }

    /**
     * Force the cut-over, so a test can read both ways at any size.
     * Pass null to measure it again.
     */
    public static function useThreshold(?int $threshold): void
    {
        self::$threshold = $threshold;
    }

    protected static function threshold(QueryBuilder $query, Model $activity): int
    {
        // The highest id stands in for the table's size: an index endpoint,
        // where COUNT(*) scans on PostgreSQL and InnoDB.
        $history = (int) $query->newQuery()->from($activity->getTable())->max($activity->getKeyName());

        return max(100, (int) (10 * sqrt($history)));
    }
}
