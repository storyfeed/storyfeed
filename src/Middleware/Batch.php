<?php

namespace Storyfeed\Middleware;

use Carbon\CarbonInterval;
use Closure;
use InvalidArgumentException;
use Storyfeed\Actions\AssignToBatch;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use WeakMap;

/**
 * Join the actor's sitting: what one person did in one go. The package
 * registers it as `batch` and puts it in the `default` group, so every verb
 * batches unless it says otherwise.
 *
 *     Story::for(Todo::class)->verb('add')->batched(within: '5 minutes');   // batch:5 minutes
 *     Story::for(Project::class)->verb('create')->unbatched();              // no batch
 *
 * With no argument the window is `storyfeed.grouping.batch.quiet_minutes`.
 * An argument is an interval Carbon reads (`5 minutes`, `PT5M`), or a
 * number of minutes (`batch:5`).
 *
 * It works after `$next`, on the stored row, so it sees the activity exactly
 * as it was written. Each batched activity moves the sitting's `closes_at`
 * to its own `published_at` plus its own window, if that is later. An
 * activity published before `closes_at` joins; one at or after it closes the
 * sitting and opens the next. An activity this middleware never sees (an
 * unbatched verb) doesn't join, extend or close anything.
 *
 * Given twice (the default group's `batch` and a verb's `batch:3`, without
 * `withoutMiddleware('batch')`), the activity is batched once: by the
 * innermost, which is the one declared last and so the verb's own. The
 * others step aside rather than count it again.
 *
 * `storyfeed.grouping.batch.enabled = false` makes it a no-op.
 */
class Batch
{
    /** @var WeakMap<PendingActivity, true>|null activities a batch further in has already handled */
    private static ?WeakMap $handled = null;

    public function handle(PendingActivity $activity, Closure $next, ?string $within = null): mixed
    {
        $published = $next($activity);

        $handled = self::$handled ??= new WeakMap;

        if (isset($handled[$activity])) {
            return $published;
        }

        $handled[$activity] = true;

        // A short circuit further in, a row born superseded, recording off
        // and the fake all leave nothing stored to batch.
        if (! $published instanceof Activity || ! $published->exists || $published->trashed()) {
            return $published;
        }

        if (! config('storyfeed.grouping.batch.enabled', true)) {
            return $published;
        }

        $window = self::window($within);

        // Its own transaction: the actor's lock row is held until it commits,
        // which is what makes a second publish by the same actor see this
        // one's batch. Inside the app's own transaction, it is a savepoint.
        $published->getConnection()->transaction(fn () => (new AssignToBatch)($published, $window));

        return $published;
    }

    /**
     * The window an argument names, or the configured one without an argument.
     */
    public static function window(?string $within): CarbonInterval
    {
        if ($within === null || trim($within) === '') {
            return CarbonInterval::minutes((int) config('storyfeed.grouping.batch.quiet_minutes', 10));
        }

        $within = trim($within);

        if (ctype_digit($within)) {
            return CarbonInterval::minutes((int) $within);
        }

        try {
            $interval = CarbonInterval::make($within);
        } catch (\Throwable) {
            $interval = null;
        }

        if ($interval === null || $interval->invert || $interval->totalSeconds <= 0) {
            throw new InvalidArgumentException(
                "The batch middleware was given '{$within}', which is not a positive interval. Give one Carbon reads, like 'batch:5 minutes', or a number of minutes."
            );
        }

        return $interval;
    }
}
