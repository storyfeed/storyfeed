<?php

namespace Storyfeed;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\Actions\AssignToBatch;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Exceptions\IncompleteActivity;
use Storyfeed\Exceptions\UnauthoredActivity;
use Storyfeed\Exceptions\UnknownVerb;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Testing\StoryfeedFake;

/**
 * Fluent builder for publishing activities.
 *
 *   Storyfeed::activity(ActivityVerb::Confirm, $delivery)
 *       ->actor($user)
 *       ->for($customer)
 *       ->publish();
 *
 * Verbs are free-form strings; a FeedVerb enum (or any backed enum) is an
 * authoring convenience that resolves to the same string.
 *
 * @phpstan-consistent-constructor
 */
class PendingActivity
{
    use Conditionable;

    public Activity $activity;

    protected bool $replace = false;

    /** @var array<string, Model> */
    protected array $entities = [];

    /** @var array<int, Model> composite members-to-be (see objects()) */
    protected array $objects = [];

    public function __construct(string|FeedVerb|BackedEnum|null $verb = null, Model|string|null $object = null)
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $this->activity = new $model;

        if ($verb !== null) {
            $this->verb($verb, $object);
        }
    }

    public static function make(string|FeedVerb|BackedEnum|null $verb = null, Model|string|null $object = null): static
    {
        return new static($verb, $object);
    }

    public function verb(string|FeedVerb|BackedEnum $verb, Model|string|null $object = null): static
    {
        $this->activity->verb = $this->normalizeVerb($verb);

        if ($object) {
            $this->object($object);
        }

        return $this;
    }

    public function actor(Model|string|null $model = null): static
    {
        return $this->associate('actor', $model);
    }

    public function object(Model|string|null $model = null): static
    {
        if ($model !== null && $this->objects !== []) {
            throw new InvalidArgumentException('An activity takes object() OR objects(), not both.');
        }

        return $this->associate('object', $model);
    }

    /**
     * A COMPOSITE: one authored story whose object is a collection —
     * "Tomás uploaded 6 files to Spring Campaign". Publishes the story
     * (object-less parent) plus one atomic member activity per model; the
     * atomics are the timeline (->log() shows them), the composite is the
     * story (grouped/curated show one node, AS2 serializes the object as an
     * OrderedCollection). See docs/grouping.md.
     *
     * @param  iterable<int, Model>  $models
     */
    public function objects(iterable $models): static
    {
        if ($this->activity->object_type !== null) {
            throw new InvalidArgumentException('An activity takes object() OR objects(), not both.');
        }

        foreach ($models as $model) {
            $this->objects[] = $model;
        }

        return $this;
    }

    public function target(Model|string|null $model = null): static
    {
        return $this->associate('target', $model);
    }

    public function context(Model|string|null $model = null): static
    {
        return $this->associate('context', $model);
    }

    public function in(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    public function to(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    public function for(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    public function from(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    public function data(array $data): static
    {
        $this->activity->data = $data;

        return $this;
    }

    public function publishedAt(DateTimeInterface|string $date): static
    {
        $this->activity->published_at = $date instanceof DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return $this;
    }

    public function replace(bool $replace = true): static
    {
        $this->replace = $replace;

        return $this;
    }

    public function publishAndReplace(): Activity
    {
        return $this->replace()->publish();
    }

    public function publish(): Activity
    {
        if (blank($this->activity->verb)) {
            throw IncompleteActivity::missingVerb();
        }

        $manager = app(StoryfeedManager::class);

        $this->resolveDefaultActor($manager);

        $this->assertAuthored($manager);

        if ($manager instanceof StoryfeedFake) {
            return $this->captureOnFake($manager);
        }

        // Stamped HERE, not only in the model's creating hook: a consumer
        // seeding inside WithoutModelEvents (the starter kit's default!)
        // would otherwise persist published_at = NULL and every activity
        // silently vanishes from the feed. "Published means timestamped"
        // must not depend on model events being enabled.
        $this->activity->published_at ??= now();

        if ($this->objects !== []) {
            return $this->publishComposite();
        }

        $activity = DB::transaction(function () {
            $this->snapshotEntities();

            $this->activity->save();

            $this->writeGroupings();

            if ($this->replace && $this->activity->object_id !== null) {
                $superseded = $this->activity->newQuery()
                    ->whereKeyNot($this->activity->getKey())
                    ->where('object_type', $this->activity->object_type)
                    ->where('object_id', $this->activity->object_id)
                    ->where('verb', $this->activity->verb);

                SyncParticipants::forget(...$superseded->pluck('id')->all());

                $superseded->delete();
            }

            return $this->activity;
        });

        ActivityPublished::dispatch($activity);

        return $activity;
    }

    /**
     * A composite: the object-less parent story plus one atomic member per
     * object, all in one transaction. Members are CLAIMED from birth —
     * their only grouping row is the composite row (winner = true), so
     * inference and curation never touch them; the parent carries a
     * composite self-row (hash = own uid, winner = null) marking it as the
     * story. One act ⇒ one batch increment (the parent) and one
     * ActivityPublished event (the parent).
     */
    protected function publishComposite(): Activity
    {
        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $parent = DB::transaction(function () use ($grouping) {
            $this->snapshotEntities();

            // The composite substrate keys on the uid (hash = parent uid),
            // so it cannot depend on the HasUlids creating hook — same
            // WithoutModelEvents lesson as published_at.
            $this->activity->uid ??= (string) Str::ulid();

            $this->activity->save();

            $grouping::query()->create([
                'activity_id' => $this->activity->getKey(),
                'bucket' => 'composite',
                'hash' => $this->activity->uid,
                'winner' => null,
            ]);

            (new SyncParticipants)($this->activity);

            foreach ($this->objects as $model) {
                $member = $this->activity->replicate([
                    'uid', 'cached_object_id',
                ]);

                $member->object()->associate($model);
                // Non-Feedable members degrade like any role: no snapshot,
                // null label at read — never withheld.
                $member->cached_object_id = $model instanceof Feedable
                    ? (new SnapshotEntity)($model)->getKey()
                    : null;
                $member->uid ??= (string) Str::ulid();
                $member->save();

                $grouping::query()->create([
                    'activity_id' => $member->getKey(),
                    'bucket' => 'composite',
                    'hash' => $this->activity->uid,
                    'winner' => true,
                ]);

                // Members carry the object, so involving($file) finds the
                // member — and the composite it belongs to surfaces through
                // the grouping join.
                (new SyncParticipants)($member);
            }

            (new AssignToBatch)($this->activity);

            return $this->activity;
        });

        ActivityPublished::dispatch($parent);

        return $parent;
    }

    /**
     * On the fake, a composite records the parent story plus each member,
     * so per-object assertions (assertPublished('upload', $file)) hold.
     */
    protected function captureOnFake(StoryfeedFake $fake): Activity
    {
        if ($this->objects === []) {
            return $fake->capture($this->activity);
        }

        $parent = $fake->capture($this->activity);

        foreach ($this->objects as $model) {
            $member = $this->activity->replicate(['uid']);
            $member->object()->associate($model);
            $fake->capture($member);
        }

        return $parent;
    }

    /**
     * Verbs are stored verbatim apart from trimming — no case folding, since
     * camelCase verbs like `updateStatus` are valid and folding would break
     * grammar keys and grouping hashes.
     */
    protected function normalizeVerb(string|FeedVerb|BackedEnum $verb): string
    {
        $resolved = match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => trim($verb),
        };

        if ($resolved === '') {
            throw IncompleteActivity::missingVerb();
        }

        if ($this->strictVerbs() && app(StoryfeedManager::class)->activityType($resolved) === null) {
            throw UnknownVerb::make($resolved);
        }

        return $resolved;
    }

    /**
     * Resolve the default actor here rather than in the model's `creating`
     * hook, so it lands in $entities and is snapshotted synchronously with
     * every other role. The model hook remains as a fallback for activities
     * created directly, bypassing this builder.
     */
    private function resolveDefaultActor(StoryfeedManager $manager): void
    {
        if ($this->activity->actor_type !== null || $this->activity->actor_id !== null) {
            return;
        }

        if ($actor = $manager->resolveActor()) {
            $this->actor($actor);
        }
    }

    /**
     * Strict verbs are a development-time assertion. Unset means "strict
     * where mistakes are cheap to fix" — never in production.
     */
    private function strictVerbs(): bool
    {
        $strict = config('storyfeed.verbs.strict');

        return $strict ?? app()->environment('local', 'testing');
    }

    /**
     * Strict grammar: publishing a (type, verb) with no headline authored is a
     * development-time error rather than a null headline in production.
     *
     * This is the sharpest answer to the failure this package keeps hearing
     * about — the grammar gets authored once, new modules ship, and nothing
     * tells you the feed has fallen behind. GrammarCoverage catches it at suite
     * level and doctor catches it at runtime, but both require someone to look.
     * This one fires at the moment the publish call is written.
     *
     * Like verbs.strict: a development-time assertion, never a storage
     * constraint, and it does not gate the icon — a missing icon degrades to a
     * wildcard, which is cosmetic, while a missing headline is a blank line.
     */
    private function assertAuthored(StoryfeedManager $manager): void
    {
        $strict = config('storyfeed.grammar.strict');

        if (! ($strict ?? app()->environment('local', 'testing'))) {
            return;
        }

        $type = $this->activity->object_type;
        $verb = (string) $this->activity->verb;

        if ($manager->templateKey($type, $verb) !== null) {
            return;
        }

        throw UnauthoredActivity::make($type, $verb);
    }

    /**
     * A string names a Party — a participant that lives only in the feed.
     */
    private function associate(string $role, Model|string|null $participant): static
    {
        $model = is_string($participant)
            ? $this->party($participant)
            : $participant;

        if ($model instanceof Model) {
            $this->activity->{$role}()->associate($model);
            $this->entities[$role] = $model;
        }

        return $this;
    }

    private function party(string $name): ?Party
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return app(StoryfeedManager::class)->party($name);
    }

    /**
     * Write one candidate grouping hash per axis, for curation to select
     * among at read time.
     */
    private function writeGroupings(): void
    {
        (new WriteGroupings)($this->activity);

        // The involving index. In the same transaction as the activity, so a
        // participant row can never outlive (or precede) the row it points at.
        (new SyncParticipants)($this->activity);

        // Batching is invisible to the recording code: the activity joins
        // (or opens) its actor's current batch here, in the same
        // transaction. Infrastructure only — no feed effect.
        (new AssignToBatch)($this->activity);

        // Curation is a policy, not a process: it is pure, idempotent and
        // touches only the <= 3 clusters this activity emits, so it runs
        // inline. Async is a write-latency optimization to reach for when a
        // real number demands it — not a correctness requirement.
        if (config('storyfeed.grouping.curate', true)) {
            (new CurateCluster)($this->activity);
        }
    }

    /**
     * Snapshot every Feedable entity synchronously, inside the publish
     * transaction, so a new activity is never invisible or degraded.
     */
    private function snapshotEntities(): void
    {
        foreach ($this->entities as $role => $model) {
            if ($model instanceof Feedable) {
                $snapshot = (new SnapshotEntity)($model);

                $this->activity->{'cached_'.$role.'_id'} = $snapshot->getKey();
            }
        }
    }
}
