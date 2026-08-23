<?php

namespace Storyfeed\Demo;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a cast into a deterministic sequence of beats spread over recent days.
 *
 * DETERMINISM IS THE FEATURE. The same seed produces the same feed, down to the
 * minute, so a demo can be rehearsed: the group you pointed at in practice is
 * the group that is there on stage, and a screenshot in the docs still matches
 * the app a reader seeds tomorrow. Randomness comes from a small LCG carried on
 * this object rather than `mt_rand()`, so seeding the demo cannot disturb — or
 * be disturbed by — anything else in the process.
 *
 * The days are SHAPED rather than sampled. A uniformly random feed reliably
 * produces a wall of solo nodes: grouping needs a burst of the same verb by the
 * same actor on the same day to have anything to collapse, and that does not
 * happen often enough by chance to be worth demoing. So each day is composed of
 * moments that are each there to make one engine feature visible:
 *
 *   - a morning upload burst        → repeat grouping, ":actor uploaded 8 files"
 *   - a thread on one document      → actor collapse, ":actors commented on X"
 *   - an afternoon of task closing  → target collapse, "across 3 projects"
 *   - scattered singles             → solo nodes, so the page is not all groups
 *
 * A feed of nothing but groups is as unconvincing as a feed of nothing but
 * rows; the mix is what reads as a real week of work.
 */
class Screenplay
{
    private int $state;

    public function __construct(
        private readonly Cast $cast,
        private readonly int $days = 7,
        int $seed = 1,
    ) {
        // Any non-zero state; the seed is mixed rather than used raw so that
        // seeds 1, 2, 3 produce visibly different weeks instead of near-neighbours.
        $this->state = ($seed * 2654435761) & 0x7FFFFFFF ?: 1;
    }

    /**
     * Every beat, oldest first.
     *
     * @return list<Beat>
     */
    public function beats(): array
    {
        $beats = [];

        for ($day = $this->days - 1; $day >= 0; $day--) {
            $beats = [...$beats, ...$this->day($day)];
        }

        usort($beats, fn (Beat $a, Beat $b) => $a->publishedAt <=> $b->publishedAt);

        return $beats;
    }

    /**
     * One working day.
     *
     * @return list<Beat>
     */
    private function day(int $daysAgo): array
    {
        $beats = [];

        $member = $this->pick($this->cast->members);
        $project = $this->pick($this->cast->projects);

        // Morning: one member uploads a run of files into one project. Same
        // actor, same verb, same day — the repeat axis has something to collapse.
        // Distinct documents: you upload different files, not the same file nine
        // times. It also decides which axis wins — repeating one object hands the
        // group to the `object` axis ("uploaded Style Guide 4 times"), which is a
        // true reading of implausible data rather than the repeat collapse this
        // moment exists to show.
        $burst = 4 + $this->next(6);

        foreach ($this->distinct($this->cast->documents, $burst) as $i => $document) {
            $beats[] = new Beat(
                verb: Vocabulary::UPLOAD,
                publishedAt: $this->at($daysAgo, 9, $i * 3 + $this->next(3)),
                actor: $member,
                object: $document,
                context: $project,
            );
        }

        // Midday: a thread. Several DIFFERENT members on ONE document, which is
        // what the actors axis collapses — a different shape from the burst above.
        $document = $this->pick($this->cast->documents);
        $thread = 3 + $this->next(3);

        foreach ($this->distinct($this->cast->members, $thread) as $i => $commenter) {
            $beats[] = new Beat(
                verb: Vocabulary::COMMENT,
                publishedAt: $this->at($daysAgo, 12, $i * 7 + $this->next(5)),
                actor: $commenter,
                object: $document,
                context: $project,
            );
        }

        // Afternoon: one member closing tasks across several projects — the
        // targets axis, "Marcus completed tasks across 3 projects".
        $closer = $this->pick($this->cast->members);

        foreach ($this->distinct($this->cast->projects, 2 + $this->next(2)) as $i => $across) {
            $beats[] = new Beat(
                verb: Vocabulary::COMPLETE,
                publishedAt: $this->at($daysAgo, 15, $i * 11 + $this->next(6)),
                actor: $closer,
                object: $this->pick($this->cast->tasks),
                target: $across,
            );
        }

        // Singles, so the page is not wall-to-wall groups.
        $beats[] = new Beat(
            verb: Vocabulary::CREATE,
            publishedAt: $this->at($daysAgo, 10, $this->next(40)),
            actor: $this->pick($this->cast->members),
            object: $this->pick($this->cast->tasks),
            context: $this->pick($this->cast->projects),
        );

        $beats[] = new Beat(
            verb: Vocabulary::APPROVE,
            publishedAt: $this->at($daysAgo, 16, $this->next(40)),
            actor: $this->pick($this->cast->members),
            object: $this->pick($this->cast->documents),
            target: $this->pick($this->cast->clients),
            context: $this->pick($this->cast->projects),
        );

        // An occasional invite, so not every day is identical in shape.
        if ($this->next(3) === 0) {
            $beats[] = new Beat(
                verb: Vocabulary::INVITE,
                publishedAt: $this->at($daysAgo, 11, $this->next(30)),
                actor: $this->pick($this->cast->members),
                object: $this->pick($this->cast->members),
                context: $this->pick($this->cast->projects),
            );
        }

        return $beats;
    }

    /** A timestamp inside working hours on a given day. */
    private function at(int $daysAgo, int $hour, int $minutes): CarbonInterface
    {
        return Carbon::now()->subDays($daysAgo)->startOfDay()->addHours($hour)->addMinutes($minutes);
    }

    /**
     * @param  list<string>  $pool
     */
    private function pick(array $pool): string
    {
        return $pool[$this->next(count($pool))];
    }

    /**
     * `$count` different members of the pool (or the whole pool, if it is
     * smaller). Distinctness is what the actors axis needs — picking at random
     * would repeat an actor and quietly turn a thread back into a repeat group.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function distinct(array $pool, int $count): array
    {
        $remaining = $pool;
        $taken = [];

        for ($i = 0; $i < $count && $remaining !== []; $i++) {
            $index = $this->next(count($remaining));
            $taken[] = $remaining[$index];
            array_splice($remaining, $index, 1);
        }

        return $taken;
    }

    /** Next pseudo-random integer in [0, $max). */
    private function next(int $max): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $max > 0 ? intdiv($this->state, 65536) % $max : 0;
    }
}
