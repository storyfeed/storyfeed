<?php

namespace Storyfeed\Testing;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;

/**
 * Records activities in memory instead of persisting them.
 *
 *   Storyfeed::fake();
 *   // exercise the code under test
 *   Storyfeed::assertPublished('confirm', $delivery);
 *
 * Deliberately side-effect free: nothing is written, no snapshots are taken,
 * no grouping hashes are computed, and ActivityPublished is not dispatched.
 * Use Event::fake() to assert on events.
 *
 * Registries (grammar, icons, verbs, object types) are inherited from the
 * real manager, so anything a service provider registered still resolves —
 * which is what lets GrammarCoverage assert against recorded activities.
 */
class StoryfeedFake extends StoryfeedManager
{
    /** @var Collection<int, Activity> */
    protected Collection $recorded;

    /** @var array<string, Party> */
    protected array $parties = [];

    protected int $sequence = 0;

    protected int $partySequence = 0;

    public function __construct()
    {
        $this->recorded = new Collection;
    }

    /**
     * Carry over ALL registry state from the manager being replaced.
     *
     * Generic on purpose: a field-by-field copy silently drops every
     * registry added after it was written (the aggregate grammar registry
     * was lost exactly this way — a faked test saw a fully-authored
     * registry as 100% missing). Copying the manager's own properties
     * wholesale means the next registry cannot repeat the bug; the fake's
     * own state (recorded, parties, …) lives only on this subclass and is
     * untouched.
     */
    public function inheritFrom(StoryfeedManager $manager): static
    {
        foreach (get_object_vars($manager) as $property => $value) {
            $this->{$property} = $value;
        }

        return $this;
    }

    /**
     * Stub a party in memory rather than writing a row, so faked tests need
     * no database at all. Stubs are reused by key, mirroring Party::make().
     */
    public function party(string $name): Party
    {
        $key = Str::slug($name);

        if (isset($this->parties[$key])) {
            return $this->parties[$key];
        }

        $model = config('storyfeed.models.party', Party::class);

        $party = new $model;

        $party->forceFill([
            'id' => ++$this->partySequence,
            'key' => $key,
            'name' => $name,
            'type' => ObjectType::Service->value,
        ]);

        return $this->parties[$key] = $party;
    }

    /**
     * Record an activity instead of persisting it. Called by
     * PendingActivity::publish() while a fake is active.
     */
    public function capture(Activity $activity): Activity
    {
        $activity->uid ??= (string) Str::ulid();
        $activity->published_at ??= now();
        $activity->forceFill(['id' => ++$this->sequence]);

        $this->recorded->push($activity);

        return $activity;
    }

    /**
     * Activities recorded so far, optionally filtered by verb or callback.
     *
     * @return Collection<int, Activity>
     */
    public function published(string|FeedVerb|BackedEnum|Closure|null $verb = null): Collection
    {
        if ($verb === null) {
            return $this->recorded;
        }

        if ($verb instanceof Closure) {
            return $this->recorded->filter($verb)->values();
        }

        $needle = $this->normalize($verb);

        return $this->recorded->filter(fn (Activity $a) => $a->verb === $needle)->values();
    }

    public function assertPublished(string|FeedVerb|BackedEnum|Closure $verb, ?Model $object = null): void
    {
        $matches = $this->published($verb);

        if ($object !== null) {
            $matches = $matches->filter(fn (Activity $a) => $a->object_type === $object->getMorphClass()
                && (string) $a->object_id === (string) $object->getKey());
        }

        Assert::assertTrue(
            $matches->isNotEmpty(),
            $this->describeExpectation('Expected an activity to be published', $verb, $object),
        );
    }

    public function assertNotPublished(string|FeedVerb|BackedEnum|Closure $verb, ?Model $object = null): void
    {
        $matches = $this->published($verb);

        if ($object !== null) {
            $matches = $matches->filter(fn (Activity $a) => $a->object_type === $object->getMorphClass()
                && (string) $a->object_id === (string) $object->getKey());
        }

        Assert::assertTrue(
            $matches->isEmpty(),
            $this->describeExpectation('Expected no activity to be published', $verb, $object),
        );
    }

    public function assertPublishedCount(int $count, string|FeedVerb|BackedEnum|Closure|null $verb = null): void
    {
        $actual = $this->published($verb)->count();

        Assert::assertSame(
            $count,
            $actual,
            "Expected {$count} activities to be published, found {$actual}.".$this->recordedSummary(),
        );
    }

    public function assertNothingPublished(): void
    {
        Assert::assertTrue(
            $this->recorded->isEmpty(),
            'Expected no activities to be published.'.$this->recordedSummary(),
        );
    }

    /**
     * The distinct (object type, verb) pairs recorded — the input for
     * GrammarCoverage.
     *
     * @return array<int, array{0: string|null, 1: string}>
     */
    public function recordedPairs(): array
    {
        return $this->recorded
            ->map(fn (Activity $a) => [$a->object_type, $a->verb])
            ->unique(fn (array $pair) => ($pair[0] ?? '*').'.'.$pair[1])
            ->values()
            ->all();
    }

    /**
     * Every morph alias that appeared in ANY role — the input to the
     * `surface` check when running faked.
     *
     * Exists so `StorySurface::assertNoUnwiredSurface()` works in the same tests
     * as its two siblings. `GrammarCoverage` has been fake-aware from the start,
     * and a namespace where two of three assertions work under `fake()` is worse
     * than one where none do: the inconsistency is what sends someone to the
     * wrong conclusion about which tool is broken.
     *
     * @return array<int, string>
     */
    public function recordedAliases(): array
    {
        $aliases = [];

        foreach ($this->recorded as $activity) {
            foreach (['actor', 'object', 'target', 'context'] as $role) {
                if ($activity->{"{$role}_type"} !== null) {
                    $aliases[] = (string) $activity->{"{$role}_type"};
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Which roles each recorded verb actually filled — the input to
     * `StoryfeedManager::possibleAggregatePairs()`.
     *
     * Accumulated across every recording of a verb (union, not per-row), so a
     * verb sometimes published with a target and sometimes without reports
     * both as reachable. That union is what makes the derived matrix a superset
     * of hand-partitioning rather than a narrower slice of it.
     *
     * @return array<string, list<string>>
     */
    public function recordedRoles(): array
    {
        $map = [];

        foreach ($this->recorded as $activity) {
            foreach (['actor', 'object', 'target', 'context'] as $role) {
                if ($activity->{"{$role}_type"} === null) {
                    continue;
                }

                $map[$activity->verb][$role] = true;
            }

            $map[$activity->verb] ??= [];
        }

        return array_map(fn (array $roles) => array_keys($roles), $map);
    }

    protected function normalize(string|FeedVerb|BackedEnum $verb): string
    {
        return match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => $verb,
        };
    }

    protected function describeExpectation(string $prefix, string|FeedVerb|BackedEnum|Closure $verb, ?Model $object): string
    {
        $described = $verb instanceof Closure ? 'matching the given callback' : "with verb [{$this->normalize($verb)}]";

        if ($object !== null) {
            $described .= " for [{$object->getMorphClass()}#{$object->getKey()}]";
        }

        return "{$prefix} {$described}.".$this->recordedSummary();
    }

    protected function recordedSummary(): string
    {
        if ($this->recorded->isEmpty()) {
            return ' Nothing was published.';
        }

        $summary = $this->recorded
            ->map(fn (Activity $a) => $a->verb.($a->object_type ? " on {$a->object_type}#{$a->object_id}" : ''))
            ->implode(', ');

        return " Published: {$summary}.";
    }
}
