<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Storyfeed\Grouping\Period;
use Storyfeed\StoryfeedManager;

/**
 * How far back a bounded curation pass looks: `storyfeed.curate.window`
 * days, and further for a verb whose groups live longer than that.
 *
 * A day's group can gain no members once the day is over, which is what lets
 * the scheduled run look back two days instead of walking all of history. A
 * week's group stays open for seven, and a month's for up to thirty-one, so
 * a verb declaring one (`->groupedWeekly()`) is looked back over its whole
 * period plus a day. Only that verb is: one weekly verb doesn't make every
 * verb's curation scan eight days.
 *
 * Derived from the declarations, never configured, so nobody keeps the
 * window and the periods in sync by hand.
 */
final class CurationWindow
{
    /**
     * The declarations that need longer than `$days`, keyed `type.verb`
     * (wildcards allowed), each with the days it needs.
     *
     * @return array<string, int>
     */
    public static function wider(int $days): array
    {
        $wider = [];

        foreach (app(StoryfeedManager::class)->storyPeriods() as $key => $period) {
            if (($needs = Period::from($period)->lookBackDays()) > $days) {
                $wider[$key] = $needs;
            }
        }

        return $wider;
    }

    /** The longest any activity is looked back over: `$days`, or a declared period's. */
    public static function widest(int $days): int
    {
        return max([$days, ...array_values(self::wider($days))]);
    }

    /**
     * Limit a query over activities to what a pass of `$days` reaches.
     *
     * A wildcard declaration (`*.publish`, `recipe.*`) reaches every row it
     * could apply to, a more specific daily one included: looking back too
     * far costs a little time, and never changes an answer.
     *
     * @param  Builder|EloquentBuilder<*>  $query
     * @param  string  $table  the activities table, when the query joins others
     */
    public static function constrain(Builder|EloquentBuilder $query, int $days, string $table = ''): void
    {
        $column = fn (string $name) => $table === '' ? $name : "{$table}.{$name}";
        $since = fn (int $days) => Chronology::stamp(now()->subDays($days));

        $query->where(function ($query) use ($days, $column, $since) {
            $query->where($column('published_at'), '>=', $since($days));

            foreach (self::wider($days) as $key => $needs) {
                [$type, $verb] = explode('.', $key, 2);

                $query->orWhere(fn ($query) => $query
                    ->where($column('published_at'), '>=', $since($needs))
                    ->when($type !== '*', fn ($query) => $query->where($column('object_type'), $type))
                    ->when($verb !== '*', fn ($query) => $query->where($column('verb'), $verb)));
            }
        });
    }
}
