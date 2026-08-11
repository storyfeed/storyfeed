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
     * Carry over registry state from the manager being replaced.
     */
    public function inheritFrom(StoryfeedManager $manager): static
    {
        $this->grammar = $manager->grammar;
        $this->icons = $manager->icons;
        $this->verbs = $manager->verbs;
        $this->objectTypes = $manager->objectTypes;

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
