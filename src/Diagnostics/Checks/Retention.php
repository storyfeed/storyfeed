<?php

namespace Storyfeed\Diagnostics\Checks;

use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Chronology;

/**
 * How long each verb's activities are kept, seen from the rows.
 *
 *  - WARNING `retention.backlog`: rows already more than a day past their
 *    window. The next `storyfeed:prune` deletes them, and the snapshots and
 *    tombstones only they name. A day of slack keeps a feed pruned daily
 *    quiet; what trips it is a window just declared or shortened over old
 *    rows, or a window nothing runs. `--pretend` shows the run first.
 *  - INFO `retention.unbounded`: a verb recorded HIGH_VOLUME times in the
 *    last RECENT_DAYS days that no window reaches, so its rows are kept for
 *    the life of the table. A verb that said `->keepForever()` has decided,
 *    and is never named.
 */
class Retention extends Check
{
    /** Rows in the last RECENT_DAYS days that make a verb high-volume. */
    public const HIGH_VOLUME = 10_000;

    public const RECENT_DAYS = 30;

    public function name(): string
    {
        return 'retention';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        try {
            $storyfeed->ensureStoriesCompiled();
        } catch (StoryMisconfigured) {
            return;
        }

        yield from $this->backlog();
        yield from $this->unbounded($storyfeed);
    }

    /** @return iterable<Finding> */
    protected function backlog(): iterable
    {
        $prune = new PruneActivities;
        $slices = $prune->slices();

        if ($slices === null || $slices === []) {
            return;
        }

        $counts = $prune->expired($slices, Carbon::now()->subDay())->toBase()
            ->select('verb')->selectRaw('count(*) as aggregate')
            ->groupBy('verb')->orderBy('verb')
            ->pluck('aggregate', 'verb');

        foreach ($counts as $verb => $count) {
            $windows = array_unique(array_map(
                fn (array $slice) => self::describe($slice['window']),
                array_filter($slices, fn (array $slice) => in_array($slice['verb'], [null, (string) $verb], true)),
            ));

            yield Finding::warning(
                'retention.backlog',
                number_format((int) $count)." `{$verb}` ".((int) $count === 1 ? 'activity is' : 'activities are')
                .' more than a day past '.(count($windows) === 1 ? 'its window ('.reset($windows).')' : 'their windows ('.implode(', ', $windows).')')
                .'. The next `storyfeed:prune` permanently deletes them, with the snapshots and tombstones only they '
                .'reference. Run `storyfeed:prune --pretend` to see the run first; if nothing runs `storyfeed:prune`, '
                .'schedule it.',
                ['verb' => (string) $verb, 'count' => (string) $count],
            );
        }
    }

    /** `P30D` as "30 days", as it was most likely declared; anything else as Carbon says it. */
    public static function describe(string $window): string
    {
        if (preg_match('/^P(\d+)D$/', $window, $days) === 1) {
            return $days[1].' '.str('day')->plural((int) $days[1]);
        }

        return CarbonInterval::make($window)?->forHumans() ?? $window;
    }

    /** @return iterable<Finding> */
    protected function unbounded(StoryfeedManager $storyfeed): iterable
    {
        $global = config('storyfeed.prune.after_days');

        // Every verb that says nothing takes the global window.
        if ($global !== null) {
            return;
        }

        // A tombstoned row under its former type, whose window prune applies.
        $query = $this->activities();
        $objectType = $this->objectTypeOf($query);

        $rows = $query->toBase()
            ->where('published_at', '>=', Chronology::stamp(Carbon::now()->subDays(self::RECENT_DAYS)))
            ->selectRaw("{$objectType} as object_type, verb, count(*) as aggregate")
            ->groupByRaw("{$objectType}, verb")
            ->get();

        /** @var array<string, int> $kept verb => rows no window reaches */
        $kept = [];

        foreach ($rows as $row) {
            $type = $row->object_type === null ? null : (string) $row->object_type;
            $declared = $storyfeed->retention($type, (string) $row->verb);

            // A window, or `->keepForever()`: either way, decided.
            if ($declared !== null) {
                continue;
            }

            $kept[(string) $row->verb] = ($kept[(string) $row->verb] ?? 0) + (int) $row->aggregate;
        }

        ksort($kept);

        foreach ($kept as $verb => $count) {
            if ($count < self::HIGH_VOLUME) {
                continue;
            }

            yield Finding::info(
                'retention.unbounded',
                'Verb `'.$verb.'` was recorded '.number_format($count).' times in the last '.self::RECENT_DAYS.' days '
                .'and no retention window reaches it, so every row is kept for the life of the table. If its '
                ."activities stop mattering after a while, declare `Story::verb('{$verb}')->keepFor('90 days')` "
                .'(or set `storyfeed.prune.after_days`) and schedule `storyfeed:prune`; if they are the record, '
                .'`->keepForever()` says so.',
                ['verb' => $verb, 'count' => (string) $count],
            );
        }
    }
}
