<?php

namespace Storyfeed\Sources;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Storyfeed\Contracts\FeedSource;
use Storyfeed\FeedCandidate;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;

/**
 * One page read from a source other than the database, in memory.
 *
 * It answers what the SQL read answers, the same way. Live groups are what
 * the write path would have stamped had the items been published in time
 * order: candidate hashes from the grouping strategy, burst windows replayed
 * chronologically (as `storyfeed:curate --rebuild-bursts` replays them), and
 * each activity's winner decided by the axes' eligibility over every item in
 * the source — curation sees whole clusters, as it does in storage, and the
 * read's filters then narrow the members. Pages are ordered and tiebroken as
 * FeedBuilder::selectItems() orders them.
 *
 * @internal
 */
final class SourceRead
{
    protected const RANK_GROUP = 0;

    protected const RANK_SOLO = 1;

    /**
     * @param  Closure(Activity): bool  $admits  the read's filters
     */
    public function __construct(
        protected FeedSource $source,
        protected Closure $admits,
        protected bool $group,
        protected int $limit,
        protected ?string $cursor,
        protected int $childrenLimit,
        protected int $offset = 0,
    ) {}

    /**
     * Every item in the source, as unsaved activities.
     *
     * @return Collection<int, Activity>
     */
    public static function activities(FeedSource $source): Collection
    {
        $activities = Collection::make();
        $uids = [];
        $position = 0;

        foreach ($source->items() as $item) {
            $position++;

            if (($item = self::item($item)) instanceof Activity) {
                $activities->push($item);

                continue;
            }

            $item = SourceItem::from($item);
            $uid = $item->identity();

            // Two identical items are two activities, and keep two ids.
            $uids[$uid] = ($uids[$uid] ?? 0) + 1;

            $activities->push($item->toActivity($position, $uids[$uid] === 1 ? $uid : "{$uid}-{$uids[$uid]}"));
        }

        return $activities;
    }

    /**
     * What a source handed over, checked: a source is app code, and its
     * items() only promises in a docblock.
     *
     * @return SourceItem|Activity|array<string, mixed>
     */
    protected static function item(mixed $item): SourceItem|Activity|array
    {
        if (! is_array($item) && ! $item instanceof SourceItem && ! $item instanceof Activity) {
            throw new InvalidArgumentException(sprintf(
                'A source item must be an array or a %s, %s given.', SourceItem::class, get_debug_type($item),
            ));
        }

        return $item;
    }

    /** @return array{Collection<int, GroupSlice>, string|null} */
    public function page(): array
    {
        $activities = self::activities($this->source);
        $winners = $this->group ? $this->curate($activities) : [];
        $admitted = $activities->filter($this->admits)->values();

        $candidates = $this->candidates($admitted, $winners)
            ->sort(fn (array $a, array $b): int => $this->compare($a[0], $b[0]))
            ->values();

        if (($after = $this->decodedCursor()) !== null) {
            $candidates = $candidates->filter(fn (array $candidate) => $this->compareToCursor($candidate[0], $after) > 0)->values();
        }

        $more = $candidates->count() > $this->offset + $this->limit;
        $page = $candidates->slice($this->offset, $this->limit)->values();
        $next = $more ? $this->encodeCursor($page->last()[0]) : null;

        $slices = $page->map(fn (array $candidate): GroupSlice => $candidate[0]->isGroup()
            ? GroupSlice::group(
                (string) $candidate[0]->axis,
                (string) $candidate[0]->hash,
                $candidate[0]->count,
                $candidate[1]->take($this->childrenLimit)->values(),
                ...$this->distinct($candidate[1]),
            )
            : GroupSlice::solo($candidate[0]->activity));

        return [$slices, $next];
    }

    /**
     * Each admitted activity as a candidate: grouped under its winner, or
     * solo when it has none.
     *
     * @param  Collection<int, Activity>  $admitted
     * @param  array<int|string, array{string, string}>  $winners
     * @return Collection<int, array{FeedCandidate, Collection<int, Activity>}>
     */
    protected function candidates(Collection $admitted, array $winners): Collection
    {
        $groups = [];
        $candidates = Collection::make();

        foreach ($admitted as $activity) {
            $winner = $winners[$activity->getKey()] ?? null;

            if ($winner === null) {
                $candidates->push([FeedCandidate::solo(self::stamp($activity), $activity), Collection::make([$activity])]);

                continue;
            }

            $groups[$winner[0]."\x1f".$winner[1]][] = $activity;
        }

        foreach ($groups as $key => $members) {
            [$axis, $hash] = explode("\x1f", $key, 2);
            $members = Collection::make($members)
                ->sort(fn (Activity $a, Activity $b): int => strcmp(self::stamp($b), self::stamp($a)) ?: $b->getKey() <=> $a->getKey())
                ->values();

            $candidates->push([
                FeedCandidate::group(self::stamp($members->first()), $axis, $hash, $members->count()),
                $members,
            ]);
        }

        return $candidates;
    }

    /**
     * Each activity's winning [axis, hash], decided over the whole source.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int|string, array{string, string}>
     */
    protected function curate(Collection $activities): array
    {
        $manager = app(StoryfeedManager::class);
        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));
        $uncurated = $manager->uncuratedBuckets();

        $hashes = [];
        $clusters = [];
        $bursts = [];

        $chronological = $activities->sort(fn (Activity $a, Activity $b): int => strcmp(self::stamp($a), self::stamp($b)) ?: $a->getKey() <=> $b->getKey());

        foreach ($chronological as $activity) {
            $own = array_diff_key($strategy->hashes($activity), array_flip($uncurated));

            foreach ($own as $axis => $logical) {
                if (! $manager->axis($axis)?->usesBursts()) {
                    continue;
                }

                $own[$axis] = $this->burst($bursts, $axis, $logical, $activity, $manager);
            }

            $hashes[$activity->getKey()] = $own;

            foreach ($own as $axis => $hash) {
                $clusters[$axis][$hash][] = $activity;
            }
        }

        $curate = (bool) config('storyfeed.grouping.curate', true);
        $eligible = [];
        $winners = [];

        foreach ($hashes as $key => $own) {
            $winner = null;

            foreach ($curate ? $manager->aggregateAxes() : [] as $axis) {
                if (isset($own[$axis]) && ($eligible["{$axis}\0{$own[$axis]}"] ??= $this->eligible($axis, $clusters[$axis][$own[$axis]], $manager))) {
                    $winner = $axis;

                    break;
                }
            }

            // The fallback, and with curation off the only axis read: the
            // SQL read's `repeat` row with no winner beside it.
            $winner ??= isset($own['repeat']) ? 'repeat' : null;

            if ($winner !== null) {
                $winners[$key] = [$winner, $own[$winner]];
            }
        }

        return $winners;
    }

    /**
     * The burst an activity joins on one axis, as AssignGroupingBursts
     * decides it: within the quiet gap of the last member and the hard
     * ceiling from the first, under the same window policy.
     *
     * @param  array<string, array{hash: string, opened: Carbon, last: Carbon, within: int, ceiling: int}>  $bursts
     */
    protected function burst(array &$bursts, string $axis, string $logical, Activity $activity, StoryfeedManager $manager): string
    {
        $key = Axis::burstKey($axis, $logical);
        [$within, $ceiling] = $manager->burstWindow($activity->object_type, (string) $activity->verb);
        $at = $activity->published_at ?? now();
        $state = $bursts[$key] ?? null;

        $joins = $state !== null
            && $state['within'] === $within && $state['ceiling'] === $ceiling
            && $at->greaterThanOrEqualTo($state['opened'])
            && $at->lessThan($state['last']->copy()->addSeconds($within))
            && $at->lessThan($state['opened']->copy()->addSeconds($ceiling));

        $hash = $joins ? $state['hash'] : 'b1:'.$key.':'.$activity->uid;

        $bursts[$key] = [
            'hash' => $hash,
            'opened' => $joins ? $state['opened'] : $at,
            'last' => $joins && $state['last']->greaterThan($at) ? $state['last'] : $at,
            'within' => $within,
            'ceiling' => $ceiling,
        ];

        return $hash;
    }

    /**
     * The axis's declared eligibility, every rule passing, as CurateCluster
     * reads it.
     *
     * @param  list<Activity>  $members
     */
    protected function eligible(string $axis, array $members, StoryfeedManager $manager): bool
    {
        $rules = $manager->axis($axis)?->eligibility() ?? [];

        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            $passes = isset($rule['distinct'])
                ? count(self::distinctEntities(Collection::make($members), $rule['distinct'])) >= ($rule['min'] ?? 1)
                : count($members) >= ($rule['members'] ?? 1);

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * True distinct counts per role across every member, and how many of
     * them are tombstones — none, in a source.
     *
     * @param  Collection<int, Activity>  $members
     * @return array{array<string, int>, array<string, int>}
     */
    protected function distinct(Collection $members): array
    {
        $distinct = [];
        $tombstoned = [];

        foreach (ActivityRoles::GROUPABLE as $role) {
            if (($count = count(self::distinctEntities($members, $role))) > 0) {
                $distinct[$role] = $count;
                $tombstoned[$role] = 0;
            }
        }

        return [$distinct, $tombstoned];
    }

    /**
     * @param  Collection<int, Activity>  $members
     * @return array<string, true>
     */
    protected static function distinctEntities(Collection $members, string $role): array
    {
        $entities = [];

        foreach ($members as $activity) {
            if (($type = $activity->{"{$role}_type"}) !== null) {
                $entities[$type."\0".$activity->{"{$role}_id"}] = true;
            }
        }

        return $entities;
    }

    /** Newest first; groups before solos at one instant; then each stream's own tiebreak. */
    protected function compare(FeedCandidate $a, FeedCandidate $b): int
    {
        return strcmp($b->latest, $a->latest)
            ?: (self::rank($a) <=> self::rank($b))
            ?: ($a->isGroup()
                ? strcmp((string) $a->axis, (string) $b->axis) ?: strcmp((string) $a->hash, (string) $b->hash)
                : $b->activity?->getKey() <=> $a->activity?->getKey());
    }

    /** @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}  $cursor */
    protected function compareToCursor(FeedCandidate $candidate, array $cursor): int
    {
        return strcmp($cursor['latest'], $candidate->latest)
            ?: (self::rank($candidate) <=> $cursor['rank'])
            ?: ($candidate->isGroup()
                ? strcmp((string) $candidate->axis, (string) $cursor['axis']) ?: strcmp((string) $candidate->hash, (string) $cursor['hash'])
                : $cursor['id'] <=> $candidate->activity?->getKey());
    }

    protected static function rank(FeedCandidate $candidate): int
    {
        return $candidate->isGroup() ? self::RANK_GROUP : self::RANK_SOLO;
    }

    /** @return array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null */
    protected function decodedCursor(): ?array
    {
        $parameters = $this->cursor === null ? null : Cursor::fromEncoded($this->cursor)?->toArray();

        if (! isset($parameters['latest'], $parameters['rank'])) {
            return null;
        }

        return [
            'latest' => Chronology::stamp((string) $parameters['latest']),
            'rank' => (int) $parameters['rank'],
            'axis' => $parameters['axis'] ?? null,
            'hash' => $parameters['hash'] ?? null,
            'id' => $parameters['id'] ?? null,
        ];
    }

    protected function encodeCursor(FeedCandidate $candidate): string
    {
        return (new Cursor([
            'latest' => $candidate->latest,
            'rank' => self::rank($candidate),
            'axis' => $candidate->axis,
            'hash' => $candidate->hash,
            'id' => $candidate->activity?->getKey(),
        ]))->encode();
    }

    protected static function stamp(Activity $activity): string
    {
        return Chronology::stamp($activity->published_at);
    }
}
