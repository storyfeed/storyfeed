<?php

namespace Storyfeed;

use BackedEnum;
use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\ForgetActivities;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Exceptions\IncompleteActivity;
use Storyfeed\Exceptions\StoryRoleMismatch;
use Storyfeed\Exceptions\UnauthoredActivity;
use Storyfeed\Exceptions\UnknownVerb;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\BodySlot;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\Feedables;
use Storyfeed\Testing\StoryfeedFake;
use UnexpectedValueException;

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
 * A `PublishesToFeed` implementor returns one of these, unpublished. A
 * message class starts it with `$this->activity($object)`, which knows the
 * class's verb; anything else names the verb inline:
 *
 *   PendingActivity::inline(ActivityVerb::Confirm)->object($delivery)->actor($user)
 *
 * QUEUED, it is a Mailable: `->queue()` sits beside `->publish()`, and
 * Laravel's own Queueable says where it goes:
 *
 *   Storyfeed::activity('confirm', $order)->onQueue('feed')->afterCommit()->queue();
 *
 * Ad-hoc means "no Story CLASS needed", not "grammar inline". Grammar resolves
 * at read time from the compiled registries, and an inline headline would live
 * on an instance of an event that takes constructor arguments — so it could not
 * be compiled at boot without instantiating the event, and honouring it would
 * mean storing templates per row, duplicating the architecture and punching
 * through the frozen payload's resolution path. `Storage::build()` is the same
 * bargain: an unnamed disk, still using the same driver machinery.
 *
 * @phpstan-consistent-constructor
 */
class PendingActivity
{
    use Conditionable, Queueable, SerializesAndRestoresModelIdentifiers;

    public Activity $activity;

    private bool $anonymous = false;

    /** This publish inserted the row, so it has no groupings or participants to rewrite yet. */
    private bool $inserted = false;

    /** @var array<string, Model> */
    protected array $entities = [];

    /** @var array<int, Model> composite members-to-be (see objects()) */
    protected array $objects = [];

    protected ?FeedThread $thread = null;

    protected ?FeedChange $change = null;

    /** Queued: snapshot the entities at `->queue()`, not on the worker. */
    private bool $snapshotNow = false;

    /** @var list<string> roles whose snapshot was taken at dispatch */
    private array $snapshotted = [];

    /**
     * Queued: drop the publish when a model it names is gone by the time the
     * worker takes it, as a job's `$deleteWhenMissingModels` does. Null
     * follows the verb's declaration, and then fails the job.
     */
    public ?bool $deleteWhenMissingModels = null;

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

    /**
     * Declare the activity inline, with no Story class.
     *
     * A thin alias of make(); it exists so the call site READS as deliberate.
     * `inline()` says "ad-hoc, on purpose", which is what makes the ad-hoc cases
     * greppable when someone later asks which events bypass the Story layer.
     */
    public static function inline(string|FeedVerb|BackedEnum $verb): static
    {
        return static::make($verb);
    }

    public function verb(string|FeedVerb|BackedEnum $verb, Model|string|null $object = null): static
    {
        $this->activity->verb = $this->normalizeVerb($verb);

        if ($object) {
            $this->object($object);
        }

        return $this;
    }

    /**
     * The action the actor took: `->by($user)->action('upload', $document)`.
     *
     * An alias for `verb()`, and the reason is the chain reading as a sentence.
     * `actor` and `action` are a pair — who acted, and what the act was — where
     * `actor` and `verb` mix a person with a part of speech.
     *
     * Only the authoring word changes. Storage is `verb` throughout and always
     * will be: the payload key, the column, the `verbs` registry, `FeedVerb`,
     * `AsFeedVerb`, and `FeedBuilder::verb()` on the read side. Reading is a
     * query rather than a sentence, so no alias there.
     */
    public function action(string|FeedVerb|BackedEnum $verb, Model|string|null $object = null): static
    {
        return $this->verb($verb, $object);
    }

    public function actor(Model|string|null $model = null): static
    {
        if ($model === null) {
            return $this->anonymously();
        }

        $this->anonymous = false;
        $this->activity->withoutDefaultActor(false);

        return $this->associate('actor', $model);
    }

    /**
     * Actor, as a byline: `->by($user)->verb('upload', $document)`.
     *
     * Not to be confused with `Storyfeed::actor($actor)`, which sets an AMBIENT
     * actor for everything recorded inside it. This sets it on one activity.
     */
    public function by(Model|string|null $model = null): static
    {
        return $this->actor($model);
    }

    /** Explicitly unknown actor; the last actor/by/anonymously call wins. */
    public function anonymously(): static
    {
        $this->anonymous = true;
        $this->activity->withoutDefaultActor();
        $this->activity->actor()->dissociate();
        $this->activity->cached_actor_id = null;
        $this->activity->unsetRelation('cachedActor');
        unset($this->entities['actor']);

        return $this;
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

    /** Source entity. Existing from() remains a target alias. Null is a no-op. */
    public function origin(Model|string|null $model = null): static
    {
        return $this->associate('origin', $model);
    }

    /** Outcome entity, not a scalar change or PHP return value. Null is a no-op. */
    public function result(Model|string|null $model = null): static
    {
        return $this->associate('result', $model);
    }

    /** Tool or service used for the act. Null is a no-op. */
    public function instrument(Model|string|null $model = null): static
    {
        return $this->associate('instrument', $model);
    }

    public function using(Model|string|null $model = null): static
    {
        return $this->instrument($model);
    }

    public function resulting(Model|string|null $model = null): static
    {
        return $this->result($model);
    }

    /**
     * ── Target aliases ───────────────────────────────────────────────────────
     *
     * Every one of these sets `target` and nothing else. They exist so a
     * recording line reads as the sentence it records; the stored row is
     * identical whichever you pick, so choose the preposition your verb takes.
     *
     * Note that `in()` and `from()` predate the `context` role and read as
     * though they might set it. They do not — see `context()`.
     */
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

    /**
     * Target, for verbs of attachment: `->verb('comment', $comment)->on($document)`.
     */
    public function on(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * Target, for verbs of sharing: `->verb('share', $document)->with($teammate)`.
     */
    public function with(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * Target, for verbs of transfer: `->verb('move', $document)->into($folder)`.
     */
    public function into(Model|string|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * The activity's data map: app values plus explicitly authored core reserved keys.
     *
     * An `Arrayable` is accepted so a typed DTO can be the authoring surface:
     * `->data(LinkFetch::from($request))` with a spatie/laravel-data object, or
     * anything else that can render itself as an array. This is the same
     * arrangement `FeedEntity` has always had for snapshot data, and the same
     * doctrine as verbs: STORAGE STAYS A PLAIN ARRAY, and the typed thing is an
     * authoring convenience that never reaches the column. A row recorded from
     * a DTO is byte-identical to one recorded from the array it produces, so a
     * DTO can be introduced or removed later without a migration and without a
     * renderer noticing.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public function data(array|Arrayable $data): static
    {
        // A NESTED Arrayable is flattened too, not just the argument itself.
        // `['ip' => $ip, 'diff' => Change::make(…)]` used to store `{}` for the
        // diff unless the app remembered `->toArray()`, which is a silent loss
        // that shows up months later in rows nobody can regenerate. The entity
        // half was fixed on 2026-09-15 and this one was missed.
        $this->activity->data = BodySlot::data($data);

        $this->writeThread();
        $this->writeChange();

        return $this;
    }

    /**
     * The utterance this row is about, and the size of the conversation
     * around it: `->thread(FeedThread::make(text: $comment->body, kind:
     * 'replied', replies: 12))`.
     *
     * ACTIVITY-SCOPED ON PURPOSE, and this is the whole design. The same
     * thread shows a different utterance on every row about it — the
     * opening on the opened row, the newest reply on the replied row — so
     * it cannot hang off the entity. See {@see FeedThread}.
     *
     * Stored inside the `data` column under the reserved `$thread` key
     * rather than in a column of its own: the shape is new and the read
     * path strips the key back out, so nothing about the storage is a
     * promise yet and no consumer has to run a migration to try it. The
     * app's own keys are untouched, and the payload's `data` is exactly
     * what `data()` was given — which is why this and `data()` are
     * ORDER-INDEPENDENT: whichever is called last, both survive.
     */
    public function thread(FeedThread $thread): static
    {
        $this->thread = $thread;

        $this->writeThread();

        return $this;
    }

    /**
     * Merge the reserved key into whatever `data` currently holds. Called
     * from both setters so neither can clobber the other.
     */
    private function writeThread(): void
    {
        if ($this->thread === null) {
            return;
        }

        $this->activity->data = [
            ...($this->activity->data ?? []),
            FeedThread::KEY => $this->thread->toArray(),
        ];
    }

    /** Set the activity's before/after facts; order-independent with data(). */
    public function change(FeedChange $change): static
    {
        $this->change = $change;
        $this->writeChange();

        return $this;
    }

    private function writeChange(): void
    {
        if ($this->change !== null) {
            $this->activity->data = $this->change->toData($this->activity->data ?? []);
        }
    }

    public function publishedAt(DateTimeInterface|string $date): static
    {
        $this->activity->published_at = $date instanceof DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return $this;
    }

    /**
     * Publish through the verb's story middleware, as a request goes through
     * its route's: the `default` group, then what the verb declared, minus
     * what it excludes ({@see StoryfeedManager::middleware()}). Each one gets
     * this PendingActivity and `$next`, and what it does after `$next()` sees
     * the stored Activity. The package's own `batch` works that way.
     *
     * WHO ACTED, highest first: the call site (`->actor()`, `->anonymously()`),
     * then a scope (`Storyfeed::actor()`, `Storyfeed::context()`, carried into a
     * job or not), then middleware, then the defaults (the verb's `->actor()`,
     * the resolver, the signed-in user, `parties.fallback`). So a middleware
     * that sets a default actor sees `hasActor()` true for anything above it,
     * `->anonymously()` included, and should leave it.
     *
     * A SHORT CIRCUIT, a middleware that returns without calling `$next`,
     * publishes nothing. What it returns is what `publish()` returns, as a
     * route middleware's response is the response: an Activity as it is, and
     * null as the unsaved Activity recording-off gives (`exists` false), the
     * way the router turns a null into an empty response. Nothing is stored
     * or dispatched, and the fake records nothing.
     *
     * AFTER COMMIT (`->afterCommit()`, or the verb's declaration) waits for
     * the surrounding database transaction, as an event that implements
     * `ShouldDispatchAfterCommit` does: the publish runs once it commits,
     * and never if it rolls back, so a feed row can't describe a write that
     * didn't happen. Until then this returns the Activity unsaved (`exists`
     * false), and the same instance is stored at the commit. Outside a
     * transaction it publishes at once.
     */
    public function publish(): Activity
    {
        if (blank($this->activity->verb)) {
            throw IncompleteActivity::missingVerb();
        }

        $manager = app(StoryfeedManager::class);

        // Before any wait for a commit, so a scope that has closed by then
        // still says who acted.
        $this->applyScopes($manager);

        $this->assertAuthored($manager);

        // Stamped HERE, not only in the model's creating hook: a consumer
        // seeding inside WithoutModelEvents (the starter kit's default!)
        // would otherwise persist published_at = NULL and every activity
        // silently vanishes from the feed. "Published means timestamped"
        // must not depend on model events being enabled. Before the
        // middleware, so it sees when the act happened.
        $this->activity->published_at ??= now();

        $type = $this->activity->object_type;
        $afterCommit = $this->afterCommit ?? $manager->queueing(is_string($type) && $type !== '' ? $type : null, (string) $this->activity->verb)['afterCommit'] ?? false;

        if ($afterCommit && app()->bound('db.transactions')) {
            $ran = false;
            $published = null;

            // Runs at once when no transaction is open.
            app('db.transactions')->addCallback(function () use (&$ran, &$published) {
                $ran = true;
                $published = $this->throughMiddleware(app(StoryfeedManager::class));
            });

            if ($ran && $published instanceof Activity) {
                return $published;
            }

            // Keyed now, so the row the commit stores is the one handed back.
            $this->activity->uid ??= (string) Str::ulid();

            return $this->activity;
        }

        return $this->throughMiddleware($manager);
    }

    /**
     * Publish on a queue worker instead, as a Mailable's `queue()` sends it
     * there: `Storyfeed::activity('confirm', $order)->queue()`.
     *
     * Where it goes is Laravel's own Queueable: `->onConnection()`,
     * `->onQueue()`, `->delay()`, `->afterCommit()`, `->through()` (job
     * middleware) and `->chain()`, each over what the verb declared in
     * routes/feed.php, then the queue config. `published_at` is stamped
     * now, so a delayed publish still lands at the moment it happened.
     *
     * On the worker it publishes as `publish()` does: the story middleware,
     * then the snapshots, unless `->snapshotNow()` took them here. A
     * `Storyfeed::actor()` or `Storyfeed::context()` around this call, and the
     * signed-in user, go with it. A model deleted before the worker takes it
     * fails the job, unless `->deleteWhenMissingModels()` says to drop it.
     *
     * With recording off nothing is queued. The fake records it as queued,
     * for `Storyfeed::assertQueued()`.
     */
    public function queue(): void
    {
        if (blank($this->activity->verb)) {
            throw IncompleteActivity::missingVerb();
        }

        $manager = app(StoryfeedManager::class);

        $this->assertAuthored($manager);

        $this->activity->published_at ??= now();

        if ($manager instanceof StoryfeedFake) {
            $this->applyScopes($manager);
            $this->resolveDefaultActor($manager);
            $this->assertRoleTypes($manager);
            $this->captureOnFake($manager, queued: true);

            return;
        }

        if (! $manager->isRecording()) {
            return;
        }

        $type = $this->activity->object_type;
        $declared = $manager->queueing(is_string($type) && $type !== '' ? $type : null, (string) $this->activity->verb);

        // The call site first, as `->onQueue()` overrides a job's `$queue`.
        $this->connection ??= $declared['connection'] ?? null;
        $this->queue ??= $declared['queue'] ?? null;
        $this->delay ??= $declared['delay'] ?? null;
        $this->afterCommit ??= $declared['afterCommit'] ?? null;
        $this->deleteWhenMissingModels ??= $declared['deleteWhenMissingModels'] ?? false;

        if ($this->snapshotNow) {
            $this->snapshotEntities();
            $this->snapshotted = array_keys($this->entities);
        }

        dispatch(new PublishQueuedActivity($this));
    }

    /**
     * Queued: take the snapshots (labels, data, media) now, as the entities
     * are at this call, instead of on the worker. Laravel says "now" for
     * "here, not on the queue" (`notifyNow()`, `Mail::sendNow()`).
     */
    public function snapshotNow(bool $now = true): static
    {
        $this->snapshotNow = $now;

        return $this;
    }

    /**
     * Queued: drop the publish silently when a model it names was deleted
     * before the worker took it — see {@see $deleteWhenMissingModels}.
     */
    public function deleteWhenMissingModels(bool $delete = true): static
    {
        $this->deleteWhenMissingModels = $delete;

        return $this;
    }

    /**
     * What a queue carries: the unsaved row's attributes, and each model by
     * its identifier, never whole, as SerializesModels carries a job's.
     * Restoring it fetches them again, and throws ModelNotFoundException for
     * one deleted since. Where it was queued is the job's to carry, not this.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'activity' => [$this->activity::class, $this->activity->getAttributes()],
            'anonymous' => $this->anonymous,
            'entities' => array_map(fn (Model $model) => $this->getSerializedPropertyValue($model), $this->entities),
            'objects' => array_map(fn (Model $model) => $this->getSerializedPropertyValue($model), $this->objects),
            'thread' => $this->thread,
            'change' => $this->change,
            'snapshotted' => $this->snapshotted,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        /** @var array{0: class-string<Activity>, 1: array<string, mixed>} $activity */
        $activity = $data['activity'];

        $this->activity = new $activity[0];
        $this->activity->setRawAttributes($activity[1]);
        $this->anonymous = (bool) $data['anonymous'];
        $this->activity->withoutDefaultActor($this->anonymous);
        $this->thread = $data['thread'];
        $this->change = $data['change'];
        $this->snapshotted = $data['snapshotted'];

        foreach ($data['entities'] as $role => $identifier) {
            $this->entities[$role] = $this->getRestoredPropertyValue($identifier);
        }

        foreach ($data['objects'] as $identifier) {
            $this->objects[] = $this->getRestoredPropertyValue($identifier);
        }
    }

    /** Through the verb's story middleware to the row. */
    private function throughMiddleware(StoryfeedManager $manager): Activity
    {
        $type = $this->activity->object_type;
        $verb = (string) $this->activity->verb;

        $published = (new Pipeline(app()))
            ->send($this)
            ->through($manager->middleware(is_string($type) && $type !== '' ? $type : null, $verb))
            ->then(function (PendingActivity $activity) use ($manager, $type, $verb): Activity {
                // The middleware was chosen for this type and verb; a row
                // that left as something else would have skipped its own.
                if ($activity->activity->object_type !== $type || (string) $activity->activity->verb !== $verb) {
                    throw new LogicException("Story middleware may not change what an activity is: [{$verb}] became [{$activity->activity->verb}].");
                }

                return $activity->persist($manager);
            });

        return match (true) {
            $published instanceof Activity => $published,
            $published === null => $this->decline(),
            default => throw new UnexpectedValueException(
                'Story middleware for ['.$verb.'] returned '.get_debug_type($published).'. Return what $next() returns, or null to publish nothing.',
            ),
        };
    }

    /**
     * Whether anyone has said who acted: an actor, or `->anonymously()`.
     * A middleware that sets a default actor asks this first.
     */
    public function hasActor(): bool
    {
        return $this->anonymous || $this->activity->actor_type !== null || $this->activity->actor_id !== null;
    }

    /** Whether the actor was said to be unknown (`->anonymously()`). */
    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    /** Whether a role is filled: `$activity->has('context')`. */
    public function has(string $role): bool
    {
        if (! in_array($role, ActivityRoles::STORED, true)) {
            throw new InvalidArgumentException("[{$role}] is not a role. The roles are ".implode(', ', ActivityRoles::STORED).'.');
        }

        return $this->activity->getAttribute("{$role}_type") !== null || isset($this->entities[$role]);
    }

    /**
     * The scopes, which rank above middleware: `Storyfeed::actor()` and
     * `Storyfeed::context()`, in this process or carried into a job. The
     * call site ranks above both, so neither touches a role it filled.
     */
    private function applyScopes(StoryfeedManager $manager): void
    {
        if (! $this->hasActor() && ($actor = $manager->applyScopedActor($this->activity)) !== null) {
            $this->actor($actor);
        }

        if ($this->activity->context_type === null && $this->activity->context_id === null
            && ! isset($this->entities['context'])) {
            $context = $manager->applyScopedContext($this->activity);
            if ($context !== null) {
                $this->entities['context'] = $context;
            }
        }
    }

    /**
     * The innermost step: the defaults, then the row, its groupings and the
     * event. What the middleware wraps.
     */
    private function persist(StoryfeedManager $manager): Activity
    {
        $this->resolveDefaultActor($manager);

        $this->assertRoleTypes($manager);

        if ($manager instanceof StoryfeedFake) {
            return $this->captureOnFake($manager);
        }

        if (! $manager->isRecording()) {
            return $this->decline();
        }

        if ($this->objects !== []) {
            return $this->publishComposite();
        }

        $keep = $this->latestKept($manager);

        $activity = DB::transaction(function () use ($keep) {
            $this->snapshotEntities();

            // A row older than a live one on its key is born superseded:
            // stored trashed (or, under force, not stored), and never
            // grouped, curated, batched or indexed, so the feed is unchanged.
            if ($keep !== null && $this->outlived($keep)) {
                return $this->bornSuperseded($keep['delete']);
            }

            $this->inserted = ! $this->activity->exists;

            $this->activity->save();

            $this->writeGroupings();

            if ($keep !== null) {
                $this->supersede($keep);
            }

            return $this->activity;
        });

        if ($activity->trashed() || ! $activity->exists) {
            return $activity;
        }

        // After the package's own transaction — and, because the event is
        // after-commit, after the consumer's outermost one when publish()
        // is called inside it. That inner transaction was only a savepoint.
        ActivityPublished::dispatch(ActivitySnapshot::fromModel($activity));

        return $activity;
    }

    /**
     * The verb's `->keepLatest()` declaration for this row, with the columns
     * it keys on filled in. Null when nothing is declared, or when a role
     * the key names is empty: an unknown actor is nobody in particular, so
     * two anonymous rows are not the same person's, and an object-less row
     * has no object to keep the latest of.
     *
     * The delete mode is validated here, before any query, not once there is
     * something to supersede: a typo that only threw on the second publish
     * would pass every first one, and the suite that never supersedes twice
     * would ship it.
     *
     * @return array{key: array<string, int|string>, within: string|null, delete: string}|null
     */
    private function latestKept(StoryfeedManager $manager): ?array
    {
        if ($this->objects !== []) {
            return null;
        }

        $type = $this->activity->object_type;
        $declared = $manager->keepLatest(is_string($type) && $type !== '' ? $type : null, (string) $this->activity->verb);

        if ($declared === null) {
            return null;
        }

        $mode = config('storyfeed.keep_latest.delete', 'soft');

        if ($mode !== 'soft' && $mode !== 'force') {
            throw new InvalidArgumentException(
                "storyfeed.keep_latest.delete must be 'soft' or 'force', got [".var_export($mode, true).'].',
            );
        }

        $key = ['verb' => (string) $this->activity->verb];

        foreach ($declared['per'] as $role) {
            $morph = $this->activity->getAttribute("{$role}_type");
            $id = $this->activity->getAttribute("{$role}_id");

            if ($morph === null || $id === null) {
                return null;
            }

            $key["{$role}_type"] = $morph;
            $key["{$role}_id"] = $id;
        }

        return ['key' => $key, 'within' => $declared['within'], 'delete' => $mode];
    }

    /**
     * The live rows on this row's key, other than itself, within the window
     * when one is declared. The window is measured both ways from this row's
     * `published_at`, so a row and a backdated one arriving after it meet
     * whichever came first.
     *
     * @param  array{key: array<string, int|string>, within: string|null, delete: string}  $keep
     * @return Builder<Activity>
     */
    private function siblings(array $keep): Builder
    {
        $query = $this->activity->newQuery()->where($keep['key']);

        if ($this->activity->exists) {
            $query->whereKeyNot($this->activity->getKey());
        }

        if ($keep['within'] !== null) {
            /** @var Carbon $at */
            $at = $this->activity->published_at;
            $window = CarbonInterval::make($keep['within']);

            $query->whereBetween('published_at', [
                Chronology::stamp($at->copy()->sub($window)),
                Chronology::stamp($at->copy()->add($window)),
            ]);
        }

        return $query;
    }

    /**
     * Whether a live row on the key was published after this one. A tie goes
     * to the row being published, as it always has.
     *
     * @param  array{key: array<string, int|string>, within: string|null, delete: string}  $keep
     */
    private function outlived(array $keep): bool
    {
        /** @var Carbon $at */
        $at = $this->activity->published_at;

        return $this->siblings($keep)->where('published_at', '>', Chronology::stamp($at))->exists();
    }

    /**
     * Store this row already superseded: soft-deleted, so the history is
     * kept and the feed is unchanged. Under `force` a superseded row is not
     * kept at all, so nothing is written and the Activity comes back
     * unsaved, as it does when recording is off.
     */
    private function bornSuperseded(string $mode): Activity
    {
        // A row already stored (a healer re-publishing it) is retired the
        // way supersede() retires any other.
        if ($this->activity->exists) {
            $id = $this->activity->getKey();

            if ($mode === 'force') {
                (new ForgetActivities)($id);
                $this->activity->newQuery()->whereKey($id)->forceDelete();
                $this->activity->exists = false;

                return $this->activity;
            }

            SyncParticipants::forget($id);
        }

        if ($mode === 'force') {
            return $this->activity;
        }

        $this->activity->setAttribute($this->activity->getDeletedAtColumn(), $this->activity->freshTimestamp());
        $this->activity->save();

        return $this->activity;
    }

    /**
     * Retire the earlier live rows on this row's key, inside the publish
     * transaction. Bulk queries on purpose: the superseded set is
     * "everything that matches", not a list of models, and Eloquent's
     * per-model delete would fire ActivityDeleted once per row for a change
     * curation cannot see anyway (the survivor keeps the cluster's hashes).
     *
     * The default is a SOFT delete, and that is deliberate. A superseded row
     * is history — "this was true and is not any more" — and the operator
     * who supersedes a status tick fifty times a day still wants to be able
     * to answer "what did it say at 14:02?". The rows leave the feed and
     * every package query through the SoftDeletes scope; their grouping rows
     * stay because nothing reaches a grouping except through the live
     * activity it points at, so they are inert, and `storyfeed:prune` sweeps
     * them with their activity. Participant rows go now, under both modes:
     * `involving()` is an index over rows that exist, and a superseded row
     * must not be findable by an entity it involved.
     *
     * `storyfeed.keep_latest.delete = 'force'` hard-deletes instead, and then
     * the grouping rows must go too — there is no DB-level cascade, by
     * design, and a hard-deleted activity may leave nothing behind that
     * points at it. That is `ForgetActivities`, the same bookkeeping prune
     * and `forceDeleteFromFeed()` do ahead of their bulk deletes.
     *
     * @param  array{key: array<string, int|string>, within: string|null, delete: string}  $keep
     */
    private function supersede(array $keep): void
    {
        $superseded = $this->siblings($keep);

        $ids = $superseded->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        if ($keep['delete'] === 'force') {
            (new ForgetActivities)(...$ids);

            $this->activity->newQuery()->whereKey($ids)->forceDelete();

            return;
        }

        SyncParticipants::forget(...$ids);

        $this->activity->newQuery()->whereKey($ids)->delete();
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

            $this->inserted = ! $this->activity->exists;

            $this->activity->save();

            $grouping::query()->create([
                'activity_id' => $this->activity->getKey(),
                'bucket' => 'composite',
                'hash' => $this->activity->uid,
                'winner' => null,
            ]);

            // The parent's partition rows, so the digest places it under its
            // person's day; the claim keeps every other axis out.
            (new WriteGroupings)($this->activity, $this->inserted);

            (new SyncParticipants)($this->activity, $this->inserted);

            foreach ($this->objects as $model) {
                $member = $this->activity->replicate([
                    'uid', 'cached_object_id',
                ]);

                $member->withoutDefaultActor($this->anonymous);
                $member->object()->associate($model);
                // Non-Feedable members degrade like any role: no snapshot,
                // null label at read — never withheld.
                $member->cached_object_id = app(Feedables::class)->isFeedable($model)
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
                (new SyncParticipants)($member, inserted: true);
            }

            return $this->activity;
        });

        ActivityPublished::dispatch(ActivitySnapshot::fromModel($parent));

        return $parent;
    }

    /**
     * Recording is off: hand back the composed Activity, unsaved.
     *
     * The return type stays `Activity` so every call site is source-compatible
     * — `->publish()->uid`, `record(...)->verb`, a Story's typed return — and
     * the row is exactly what the fake hands back minus the fabricated id:
     * `uid` and `published_at` stamped, `exists` false, `id` null. `exists`
     * is the honest tell, and it is Eloquent's own. No id is invented because
     * an invented key can be handed to a foreign key; a null one cannot.
     *
     * Nothing is dispatched. ActivityPublished means a row was written, and
     * a listener that goes on to read it back must not be told a lie. The
     * dev-time assertions above (strict verbs, strict grammar) DID run:
     * muted is not blind, and a quiet suite still catches a typo'd verb.
     *
     * Composites decline whole — the parent, unsaved; no members composed.
     */
    protected function decline(): Activity
    {
        $this->activity->uid ??= (string) Str::ulid();
        $this->activity->published_at ??= now();

        return $this->activity;
    }

    /**
     * On the fake, a composite records the parent story plus each member,
     * so per-object assertions (assertPublished('upload', $file)) hold.
     */
    protected function captureOnFake(StoryfeedFake $fake, bool $queued = false): Activity
    {
        $capture = fn (Activity $activity) => $queued ? $fake->captureQueued($activity) : $fake->capture($activity);

        $parent = $capture($this->activity);

        foreach ($this->objects as $model) {
            $member = $this->activity->replicate(['uid']);
            $member->object()->associate($model);
            $capture($member);
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
        if ($this->anonymous || $this->activity->actor_type !== null || $this->activity->actor_id !== null) {
            return;
        }

        if ($actor = $manager->applyDefaultActor($this->activity)) {
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
     * tells you the feed has fallen behind. HeadlineCoverage catches it at suite
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
     * The verb's role constraints (`->whereActor()`, `->whereRole()`), held
     * against the roles as they are about to be stored, whoever filled
     * them: a route's `->where()` checked as it matches. Compared by morph
     * alias, never class. An empty role is never a violation: an anonymous
     * actor is unknown, not the wrong type. A composite's object is each
     * of its members.
     */
    private function assertRoleTypes(StoryfeedManager $manager): void
    {
        $feedables = app(Feedables::class);

        if ($feedables->requiresMorphMap()) {
            foreach ([...array_values($this->entities), ...$this->objects] as $model) {
                $feedables->assertMorphAlias($model);
            }
        }

        $type = $this->activity->object_type;
        $type = is_string($type) && $type !== '' ? $type : null;
        $verb = (string) $this->activity->verb;

        foreach ($manager->wheres($type, $verb) as $role => $allowed) {
            $given = $role === 'object' && $this->objects !== []
                ? array_map(fn (Model $member) => $member->getMorphClass(), $this->objects)
                : [$this->activity->getAttribute("{$role}_type")];

            foreach ($given as $morph) {
                if (is_string($morph) && $morph !== '' && ! in_array($morph, $allowed, true)) {
                    throw StoryRoleMismatch::forRole($verb, ($type ?? '*').".{$verb}", $role, $allowed, $morph);
                }
            }
        }
    }

    /** A string names a Party — a participant that lives only in the feed. */
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
        (new WriteGroupings)($this->activity, $this->inserted);

        // The involving index. In the same transaction as the activity, so a
        // participant row can never outlive (or precede) the row it points at.
        (new SyncParticipants)($this->activity, $this->inserted);

        // Batching is not here: it is the `batch` story middleware, which
        // runs once this row is stored (Middleware\Batch).

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
            // Taken at `->queue()` by `->snapshotNow()`.
            if (in_array($role, $this->snapshotted, true)) {
                continue;
            }

            if (app(Feedables::class)->isFeedable($model)) {
                $snapshot = (new SnapshotEntity)($model);

                $this->activity->{'cached_'.$role.'_id'} = $snapshot->getKey();
            }
        }
    }
}
