<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Contracts\GroupingStrategy;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\StoryfeedManager;

/**
 * Activities carrying no grouping row at all — rows the read path can only
 * ever render solo.
 *
 * THE FAILURE THIS EXISTS FOR is a two-command trap, and both commands are
 * individually correct. An app that bulk-inserts history (the path past a
 * million rows) has no grouping rows yet. `storyfeed:trickle` is what converges
 * imported rows into groups — but it only looks at `uncached()` activities, and
 * `storyfeed:rebuild` caches every one of them. Run rebuild first and the
 * import is ungrouped FOREVER: it renders as a wall of solo nodes, and the
 * check an adopter reaches for reports a clean backlog, because `backlog`
 * counts uncached entities rather than missing groups.
 *
 * A trap that is silent in the exact tool someone runs to check their migration
 * is the worst shape a trap can have. This is the noise it was missing.
 *
 * WHY IT ASKS THE STRATEGY RATHER THAN COUNTING ABSENCE. Under the shipped
 * axes every activity groups — `repeat` requires no roles at all, so even a
 * bare verb emits a hash — and a check written against that assumption would be
 * correct here and wrong in the installs that most need it. `NullStrategy` is
 * shipped, `grouping.strategy` is swappable, and an app that turned grouping off
 * has an entire table of legitimately ungrouped rows. Screaming at every one of
 * them would get this check disabled, and then the trap is silent again.
 *
 * So absence is the alarm's SIZE and the strategy is its EVIDENCE: today's
 * strategy is re-run over a bounded sample, and the finding is raised only if
 * rows that WOULD group are sitting there ungrouped. That is also why the
 * message quotes both numbers — extrapolating the sample across the total would
 * be inventing precision this check does not have.
 */
class Ungrouped extends Check
{
    /** Activities to re-run the strategy over. Bounded: doctor must stay cheap. */
    protected const SAMPLE = 50;

    public function name(): string
    {
        return 'grouping';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities') || ! $this->hasTable('groupings')) {
            return; // Tables already reported it
        }

        $activities = $this->table('activities');
        $groupings = $this->table('groupings');

        $ungrouped = $this->activities()
            ->whereNotExists(fn ($sub) => $sub
                ->from($groupings)
                ->whereColumn('activity_id', "{$activities}.id"));

        $total = (clone $ungrouped)->count();

        if ($total === 0) {
            return;
        }

        // Newest first, for the same reason the trickle works that way: recent
        // rows are the ones an adopter is looking at while they migrate.
        $sample = (clone $ungrouped)->orderByDesc('published_at')->limit(self::SAMPLE)->get();

        /** @var GroupingStrategy $strategy */
        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));

        $groupable = 0;

        foreach ($sample as $activity) {
            if ($strategy->hashes($activity) !== []) {
                $groupable++;
            }
        }

        if ($groupable === 0) {
            // Nothing today's strategy would group — an app running
            // NullStrategy, or a custom one no sampled row satisfies. Silence is
            // the right answer, not an Info nobody can act on.
            return;
        }

        $sampled = $sample->count();

        yield Finding::warning(
            'grouping.ungrouped',
            "{$total} ".str('activity')->plural($total).' have no grouping rows, so the feed can only render '
            ."them one by one. Of the {$sampled} newest sampled, {$groupable} would group under today's axes. "
            .'Run `php artisan storyfeed:curate --rehash` to write the missing rows and pick winners. '
            .'(Imported rows normally converge via `storyfeed:trickle` — but the trickle only sees UNCACHED '
            .'activities, so running `storyfeed:rebuild` first leaves them here permanently.)',
            ['ungrouped' => $total, 'sampled' => $sampled, 'groupable' => $groupable],
        );
    }
}
