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
 * An ordered read keeps the items in the order the source gave them, groups
 * nothing by verb and time, and pages by position. A dateless or verbless
 * item never groups by verb and time; in a read that is not ordered,
 * dateless items follow every dated one, in the order given.
 *
 * A composite entry (Entry::objects()) reads as one `composite` group in
 * every read, its members never curated, as a recorded composite does. A
 * read that groups by context folds the other admitted items naming one
 * context into one `context` group, ordered or not, dated or not. Either
 * group stands where its first member does in an ordered read, and at its
 * newest member's time otherwise. An item with no context, or the only one
 * naming its context, reads solo.
 *
 * @internal
 */
final class SourceRead
{
    protected const RANK_GROUP = 0;

    protected const RANK_SOLO = 1;

    protected static ?Axis $contextKey = null;

    /**
     * @param  Closure(Activity): bool  $admits  the read's filters
     * @param  array{string, string}|null  $membersOf  the [axis, hash] of the
     *                                                 one group whose members
     *                                                 alone are read, solo
     */
    public function __construct(
        protected FeedSource $source,
        protected Closure $admits,
        protected bool $group,
        protected int $limit,
        protected ?string $cursor,
        protected int $childrenLimit,
        protected int $offset = 0,
        protected bool $ordered = false,
        protected bool $byContext = false,
        protected ?array $membersOf = null,
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

            $item = Entry::from($item);
            $uid = self::uid($item, $uids);

            if ($item->objects === []) {
                $activities->push($item->toActivity($position, $uid));

                continue;
            }

            // A composite reads as its members, each at its own position, as
            // a recorded composite's atomics are its timeline.
            foreach ($item->members() as $index => $member) {
                $activity = $member->toActivity($index === 0 ? $position : ++$position, self::uid($member, $uids));
                $activity->setAttribute(Entry::COMPOSITE, $uid);

                if ($item->headline !== null) {
                    $activity->setAttribute(Entry::COMPOSITE_HEADLINE, $item->headline);
                }

                $activities->push($activity);
            }
        }

        return $activities;
    }

    /**
     * The entry's id: two identical items are two activities, and keep two ids.
     *
     * @param  array<string, int>  $uids
     */
    protected static function uid(Entry $entry, array &$uids): string
    {
        $uid = $entry->identity();
        $uids[$uid] = ($uids[$uid] ?? 0) + 1;

        return $uids[$uid] === 1 ? $uid : "{$uid}-{$uids[$uid]}";
    }

    /**
     * What a source handed over, checked: a source is app code, and its
     * items() only promises in a docblock.
     *
     * @return Entry|Activity|array<string, mixed>
     */
    protected static function item(mixed $item): Entry|Activity|array
    {
        if (! is_array($item) && ! $item instanceof Entry && ! $item instanceof Activity) {
            throw new InvalidArgumentException(sprintf(
                'An entry must be an array or a %s, %s given.', Entry::class, get_debug_type($item),
            ));
        }

        return $item;
    }

    /** @return array{Collection<int, GroupSlice>, string|null, string|null} slices, next cursor, previous cursor */
    public function page(): array
    {
        $activities = self::activities($this->source);
        $winners = $this->group && ! $this->ordered && $this->membersOf === null
            // Grouping is by verb and time, so a verbless or dateless entry
            // reads solo. A composite's members belong to their composite.
            ? $this->curate($activities->filter(fn (Activity $activity) => $activity->published_at !== null && $activity->verb !== null && self::composite($activity) === null))
            : [];
        $admitted = $activities->filter($this->admits)
            ->when($this->membersOf !== null, fn (Collection $admitted) => $admitted->filter(fn (Activity $activity) => $this->claim($activity) === $this->membersOf))
            ->values();

        $candidates = $this->candidates($admitted, $winners)
            ->sort(fn (array $a, array $b): int => $this->compare($a[0], $b[0]))
            ->values();

        $before = false;
        $anchored = true;

        if (($from = $this->decodedCursor()) !== null) {
            $before = $from['before'];
            $candidates = $candidates->filter(fn (array $candidate) => $before
                ? $this->compareToCursor($candidate[0], $from) < 0
                : $this->compareToCursor($candidate[0], $from) > 0)->values();
        } elseif ($this->ordered && ($position = $this->decodedPosition()) !== null) {
            $before = $position['before'];
            $candidates = $candidates->filter(fn (array $candidate) => $before
                ? $candidate[0]->id < $position['id']
                : $candidate[0]->id > $position['id'])->values();
        } else {
            $anchored = false;
        }

        // Backward, count from the cursor: the nearest items make the page.
        if ($before) {
            $candidates = $candidates->reverse()->values();
        }

        $more = $candidates->count() > $this->offset + $this->limit;
        $page = $candidates->slice($this->offset, $this->limit)->values();

        if ($before) {
            $page = $page->reverse()->values();
        }

        $at = fn (bool $back): ?string => $page->isEmpty() ? null : $this->encodeCursor($back ? $page->first()[0] : $page->last()[0], $back);
        $next = $before || $more ? $at(false) : null;
        $prev = $before ? ($more ? $at(true) : null) : ($anchored ? $at(true) : null);

        $slices = $page->map(fn (array $candidate): GroupSlice => $candidate[0]->isGroup()
            ? GroupSlice::group(
                (string) $candidate[0]->axis,
                (string) $candidate[0]->hash,
                $candidate[0]->count,
                $candidate[1]->take($this->childrenLimit)->values(),
                ...$this->distinct($candidate[1]),
            )
            : GroupSlice::solo($candidate[0]->activity));

        return [$slices, $next, $prev];
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
        $claims = [];
        $candidates = Collection::make();

        foreach ($admitted as $activity) {
            // A composite, then a context, claims its members before any
            // verb-and-time group: both were asked for, and a member is read
            // once. A members read reads them solo.
            if ($this->membersOf === null && ($claim = $this->claim($activity)) !== null) {
                $claims[$claim[0]][$claim[1]][] = $activity;

                continue;
            }

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
                FeedCandidate::group(self::stamp($members->first()), $axis, $hash, $members->count(), $members->max(fn (Activity $member) => $member->getKey())),
                $members,
            ]);
        }

        foreach ($claims as $axis => $claimed) {
            foreach ($claimed as $hash => $members) {
                $candidates->push($this->claimed((string) $axis, (string) $hash, $members));
            }
        }

        return $candidates;
    }

    /**
     * One claimed group as a candidate, or the one member it was narrowed to.
     *
     * @param  non-empty-list<Activity>  $members
     * @return array{FeedCandidate, Collection<int, Activity>}
     */
    protected function claimed(string $axis, string $hash, array $members): array
    {
        if (count($members) === 1) {
            return [FeedCandidate::solo(self::stamp($members[0]), $members[0]), Collection::make($members)];
        }

        // In the order the feed reads them, so the first member is the
        // first in kept order, or the newest.
        $members = Collection::make($members)
            ->sort(fn (Activity $a, Activity $b): int => $this->compare(FeedCandidate::solo(self::stamp($a), $a), FeedCandidate::solo(self::stamp($b), $b)))
            ->values();

        $latest = self::stamp($members->first());

        return [
            FeedCandidate::group(
                $latest,
                $axis,
                $hash,
                $members->count(),
                // Where the group stands among positions (kept order, or
                // dateless), else its tiebreak at a shared time.
                $this->ordered || $latest === ''
                    ? $members->min(fn (Activity $member) => $member->getKey())
                    : $members->max(fn (Activity $member) => $member->getKey()),
            ),
            $members,
        ];
    }

    /**
     * The [axis, hash] of the group that claims an activity: its composite
     * entry's, else, when the read groups by context, its context's.
     *
     * @return array{string, string}|null
     */
    protected function claim(Activity $activity): ?array
    {
        return match (true) {
            ($composite = self::composite($activity)) !== null => ['composite', $composite],
            $this->byContext && ($context = self::context($activity)) !== null => ['context', $context],
            default => null,
        };
    }

    /** The composite entry an activity is a member of, as its group's hash. */
    protected static function composite(Activity $activity): ?string
    {
        $composite = $activity->getAttributes()[Entry::COMPOSITE] ?? null;

        return is_string($composite) ? $composite : null;
    }

    /**
     * The context an activity names, as its group's hash: the `context`
     * axis's pinned role, keyed the way a recipe keys `ca!:cid!`. Null when
     * it names none.
     */
    protected static function context(Activity $activity): ?string
    {
        return (self::$contextKey ??= Axis::make('context')->key('ca!:cid!'))->hashFor($activity);
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

    /**
     * As given when ordered. Otherwise newest first, dateless last in the
     * order given; groups before solos at one instant; then each stream's
     * own tiebreak.
     */
    protected function compare(FeedCandidate $a, FeedCandidate $b): int
    {
        if ($this->ordered || ($a->latest === '' && $b->latest === '')) {
            return $a->id <=> $b->id;
        }

        return strcmp($b->latest, $a->latest)
            ?: (self::rank($a) <=> self::rank($b))
            ?: ($b->id <=> $a->id)
            ?: strcmp((string) $a->axis, (string) $b->axis) ?: strcmp((string) $a->hash, (string) $b->hash);
    }

    /** @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}  $cursor */
    protected function compareToCursor(FeedCandidate $candidate, array $cursor): int
    {
        if ($cursor['latest'] === '' && $candidate->latest === '') {
            return $candidate->id <=> $cursor['id'];
        }

        return strcmp($cursor['latest'], $candidate->latest)
            ?: (self::rank($candidate) <=> $cursor['rank'])
            ?: ($cursor['id'] <=> $candidate->id)
            ?: strcmp((string) $candidate->axis, (string) $cursor['axis']) ?: strcmp((string) $candidate->hash, (string) $cursor['hash']);
    }

    protected static function rank(FeedCandidate $candidate): int
    {
        return $candidate->isGroup() ? self::RANK_GROUP : self::RANK_SOLO;
    }

    /** @return array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null, before: bool}|null */
    protected function decodedCursor(): ?array
    {
        if ($this->ordered) {
            return null;
        }

        $cursor = $this->cursor === null ? null : Cursor::fromEncoded($this->cursor);
        $parameters = $cursor?->toArray();

        if (! isset($parameters['latest'], $parameters['rank'])) {
            return null;
        }

        return [
            'latest' => $parameters['latest'] === '' ? '' : Chronology::stamp((string) $parameters['latest']),
            'rank' => (int) $parameters['rank'],
            'axis' => $parameters['axis'] ?? null,
            'hash' => $parameters['hash'] ?? null,
            'id' => $parameters['id'] ?? null,
            'before' => $cursor->pointsToPreviousItems(),
        ];
    }

    /**
     * Where an ordered read's cursor left off: the position of the item it
     * was minted at.
     *
     * @return array{id: int, before: bool}|null
     */
    protected function decodedPosition(): ?array
    {
        $cursor = $this->cursor === null ? null : Cursor::fromEncoded($this->cursor);
        $parameters = $cursor?->toArray();

        return isset($parameters['position']) && is_int($parameters['position'])
            ? ['id' => $parameters['position'], 'before' => $cursor->pointsToPreviousItems()]
            : null;
    }

    protected function encodeCursor(FeedCandidate $candidate, bool $before = false): string
    {
        if ($this->ordered) {
            return (new Cursor(['position' => $candidate->id], ! $before))->encode();
        }

        return (new Cursor([
            'latest' => $candidate->latest,
            'rank' => self::rank($candidate),
            'axis' => $candidate->axis,
            'hash' => $candidate->hash,
            'id' => $candidate->id,
        ], ! $before))->encode();
    }

    /** The activity's sort stamp; empty for a dateless one, which sorts last. */
    protected static function stamp(Activity $activity): string
    {
        return $activity->published_at === null ? '' : Chronology::stamp($activity->published_at);
    }
}
