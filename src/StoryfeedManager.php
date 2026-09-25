<?php

namespace Storyfeed;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\Bundleable;
use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Contracts\FeedHealer;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Diagnostics\Doctor;
use Storyfeed\Diagnostics\Report;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Exceptions\StoryNotFound;
use Storyfeed\Exceptions\StoryObjectMismatch;
use Storyfeed\Exceptions\UndeclaredParty;
use Storyfeed\Exceptions\UnknownFeed;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Party;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\CompileStories;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\Stories\PendingResource;
use Storyfeed\Stories\PublishQueuedStory;
use Storyfeed\Stories\Registrar;
use Storyfeed\Stories\ResourceClass;
use Storyfeed\Stories\Story;
use Storyfeed\Stories\Verb;
use Storyfeed\Support\CarryFailures;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\IgnoredParties;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\QueuedActor;
use Storyfeed\Support\QueuedContext;
use Storyfeed\Support\TombstoneRules;
use Throwable;
use WeakMap;

/**
 * @phpstan-import-type Compiled from CompileStories
 */
class StoryfeedManager
{
    protected ?Closure $actorResolver = null;

    /**
     * The innermost Storyfeed::actor() actor as a transportable identity, so a
     * job dispatched inside the scope carries it (see QueuedActor). Null
     * outside any scope; empty inside one with nothing to carry.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $scopedActor = null;

    /**
     * A queued job's scoped actor while that job runs. Kept as an identity
     * so a model deleted since dispatch still attributes, as auth does.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $queuedActor = null;

    /** @var array<int, array{?Closure, array<string, mixed>|null, array<string, mixed>|null}> */
    protected array $queuedScopes = [];

    protected ?Model $contextModel = null;

    /** @var array<string, mixed>|null */
    protected ?array $scopedContext = null;

    /** @var array<int, array{?Model, array<string, mixed>|null}> */
    protected array $queuedContexts = [];

    /**
     * The recording switch's RUNTIME half. Null defers to config; true or
     * false is a stopRecording()/startRecording() call, and wins over config
     * for the rest of this process. An instance property rather than a
     * static so it dies with the container: Laravel's test case builds a
     * fresh application per test, so a toggle can never leak into the next
     * one — the same reason Pulse keeps `$shouldRecord` on its singleton.
     */
    protected ?bool $recording = null;

    /** @var array<string, string|Closure|FeedHeadline> */
    protected array $grammar = [];

    /** @var array<string, string|Closure|FeedHeadline> */
    protected array $aggregateGrammar = [];

    /** @var array<string, string|Closure|FeedHeadline> keyed on the type → verb ladder, like grammar */
    protected array $actorlessGrammar = [];

    /** @var array<string, string> */
    protected array $icons = [];

    /**
     * Glyph intents, keyed like icons but resolved on their own — see
     * glyphIntents().
     *
     * @var array<string, string>
     */
    protected array $glyphIntents = [];

    /**
     * @var array<string, string|FeedNoun> plural forms keyed 'type' or 'type.verb'
     */
    protected array $nouns = [];

    /** @var array<string, ActivityType|string> */
    protected array $verbs = self::DEFAULT_VERBS;

    /**
     * What a redundant activity reads as (`->missingHeadline()`), on the
     * type → verb ladder. Story-compiled only.
     *
     * @var array<string, string|Closure|FeedHeadline>
     */
    protected array $missingGrammar = [];

    /**
     * How long a verb's activities are kept (`->keepFor()`, `->keepForever()`):
     * an ISO 8601 duration or `forever`, on the type → verb ladder.
     * Story-compiled only.
     *
     * @var array<string, string>
     */
    protected array $storyRetention = [];

    /**
     * Which of a verb's rows a publish supersedes (`->keepLatest()`): the
     * roles it keys on and the window, on the type → verb ladder.
     * Story-compiled only.
     *
     * @var array<string, array{per: list<string>, within: string|null}>
     */
    protected array $storyKeepLatest = [];

    /**
     * The types each constrained role of a verb may be (`->whereActor()`,
     * `->whereRole()`), as morph aliases, on the type → verb ladder.
     * Story-compiled only.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected array $storyWheres = [];

    /**
     * The calendar period a verb's groups live in (`->groupedWeekly()` and
     * the rest), as a Period's value, on the type → verb ladder.
     * Story-compiled only.
     *
     * @var array<string, string>
     */
    protected array $storyPeriods = [];

    /**
     * Where a verb's queued publishes go (`->onQueue()`, `->delay()`,
     * `->afterCommit()`, …), on the type → verb ladder. Story-compiled only.
     *
     * @var array<string, array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}>
     */
    protected array $storyQueue = [];

    /**
     * Story middleware as each definition declared it (`->middleware()`,
     * `->withoutMiddleware()`, `->batched()`), keyed `type.verb`.
     *
     * @var array<string, array{middleware: list<string|Closure>, excluded: list<string>}>
     */
    protected array $storyMiddleware = [];

    /**
     * A verb's fixed actor (`->actor('Stripe')`), a party name on the
     * type → verb ladder. Story-compiled only.
     *
     * @var array<string, string>
     */
    protected array $storyActors = [];

    /**
     * `type.verb` → the action it came from; `parts` is what an action that
     * takes the request returned when stories compiled.
     *
     * @var array<string, array{uses: string, request: bool, parts: array<string, string>|null}>
     */
    protected array $storyActions = [];

    /**
     * Story names, `name => type.verb`, as the router's nameList maps a name
     * to its route. Compiled only: a name is defined in routes/feed.php.
     *
     * @var array<string, string>
     */
    protected array $storyNames = [];

    /**
     * The party names an actor may take, slug => name. Null, the default,
     * is unguarded: apps that declare nothing behave as they always did.
     *
     * @var array<string, string>|null
     */
    protected ?array $declaredParties = null;

    /** @var array<string, true> undeclared names already recorded this process */
    protected array $ignoredParties = [];

    /** @var array<string, true> actions already reported for varying by request */
    protected array $varyingActions = [];

    /**
     * What each action that takes the request chose, per request, for the
     * jobs it dispatches. Weak, so a request under Octane takes it along.
     *
     * @var WeakMap<Request, array<string, array<string, mixed>>>|null
     */
    protected ?WeakMap $carriedActions = null;

    /** @var array<string, true> actions already recorded for throwing at dispatch */
    protected array $failedCarries = [];

    /**
     * Named feeds — an audience's scope and verb allowlist, declared once at
     * boot. Behaviour rather than data on purpose: a feed composes the whole
     * FeedBuilder (roles, mode, query()), not just a verb list — which is also
     * why, unlike the story registries, this one can never enter the compiled
     * manifest. There is nothing to cache: feeds are registered, not compiled.
     *
     * @var array<string, FeedDefinition>
     */
    protected array $feeds = [];

    /** @var array<string, FeedHealer> */
    protected array $healers = [];

    /**
     * Feed class → the key it was registered under, rebuilt on every feeds()
     * call. The reverse index behind Feed::name(): a class feed entered
     * through its own constructor has to report the SAME identity as the
     * registry key it was entered by elsewhere, and this is where the class
     * learns that key. See feedNameFor().
     *
     * @var array<class-string<Feed>, string>
     */
    protected array $feedKeys = [];

    /**
     * Verbs the app explicitly registered (vs. shipped defaults) — tracked
     * separately because an app-declared mapping may coincide with a
     * default's value, and tooling should still report it as the app's.
     *
     * @var array<string, true>
     */
    protected array $declaredVerbs = [];

    /** @var array<string, ObjectType|string> */
    protected array $objectTypes = [];

    /** @var array<string, ObjectType|string|null> */
    protected array $resolvedObjectTypes = [];

    /**
     * Built-in verb → AS2.0 activity type mappings. Unmapped verbs
     * serialize as the base type `Activity` (spec-legal).
     */
    public const DEFAULT_VERBS = [
        'accept' => ActivityType::Accept,
        'add' => ActivityType::Add,
        'announce' => ActivityType::Announce,
        'arrive' => ActivityType::Arrive,
        'block' => ActivityType::Block,
        'create' => ActivityType::Create,
        'delete' => ActivityType::Delete,
        'dislike' => ActivityType::Dislike,
        'flag' => ActivityType::Flag,
        'follow' => ActivityType::Follow,
        'ignore' => ActivityType::Ignore,
        'invite' => ActivityType::Invite,
        'join' => ActivityType::Join,
        'leave' => ActivityType::Leave,
        'like' => ActivityType::Like,
        'listen' => ActivityType::Listen,
        'move' => ActivityType::Move,
        'offer' => ActivityType::Offer,
        'question' => ActivityType::Question,
        'read' => ActivityType::Read,
        'reject' => ActivityType::Reject,
        'remove' => ActivityType::Remove,
        'share' => ActivityType::Announce,
        'tentativeAccept' => ActivityType::TentativeAccept,
        'tentativeReject' => ActivityType::TentativeReject,
        'travel' => ActivityType::Travel,
        'undo' => ActivityType::Undo,
        'update' => ActivityType::Update,
        'view' => ActivityType::View,
    ];

    /** @var array<string, Axis>|null lazy — recipes read config at first use */
    protected ?array $axes = null;

    /**
     * Morph aliases whose models are bundleable (runs of them become one
     * composite activity) — the registry override for the Bundleable marker
     * interface. Registry wins.
     *
     * @var array<string, true>
     */
    protected array $bundleables = [];

    /**
     * What routes/feed.php registered through the Story facade, in
     * registration order.
     *
     * @var list<Verb|PendingResource|BoundStory>
     */
    protected array $stories = [];

    protected bool $storiesCompiled = false;

    /**
     * The compiled output, cached in memory (or seeded from a manifest).
     *
     * @var Compiled|null
     */
    protected ?array $compiled = null;

    /**
     * What the last compile merged into the registries, so a recompile can
     * withdraw it first (see retractApplied()).
     *
     * @var Compiled|null
     */
    protected ?array $applied = null;

    /**
     * The Story classes a cached manifest was compiled from. The definitions
     * file isn't loaded when cached, so a class it registered is known only
     * from here (see hasStory()).
     *
     * @var list<string>
     */
    protected array $cachedStories = [];

    /**
     * Health checks. Null means "the shipped set" — resolved lazily so
     * Doctor::DEFAULT_CHECKS stays the single source of the default order,
     * and so an app that only appends never has to restate it.
     *
     * @var array<int, class-string<DiagnosticCheck>|DiagnosticCheck>|null
     */
    protected ?array $checks = null;

    /**
     * Begin composing an activity.
     */
    public function activity(string|FeedVerb|BackedEnum|null $verb = null, Model|string|null $object = null): PendingActivity
    {
        return PendingActivity::make($verb, $object);
    }

    /** Begin with an explicitly unknown actor. */
    public function anonymous(): PendingActivity
    {
        return $this->activity()->anonymously();
    }

    /**
     * A pending activity for a named story, `URL::route()`'s twin, as
     * `story()` is `route()`'s:
     *
     *     Storyfeed::route('order.confirm', $order)->by($user)->publish();
     *
     * Takes a name only, never a verb, as `route()` takes a name and never
     * a URI. Throws StoryNotFound for a name nothing defined, always, and
     * StoryObjectMismatch for an object of another type than the name's
     * key: the name says `order`, so a comment can't be its object. A name
     * bound to a message class throws too, naming the class to construct
     * and publish.
     *
     * A verb that has no name is recorded by its verb:
     * `Storyfeed::activity('confirm', $order)` or `Act::Confirm->of($order)`.
     */
    public function route(string|BackedEnum $name, Model|string|null $object = null): PendingActivity
    {
        // As UrlGenerator::route() takes a string-backed enum's value.
        if ($name instanceof BackedEnum) {
            if (! is_string($name->value)) {
                throw new InvalidArgumentException('Attribute [name] expects a string backed enum.');
            }

            $name = $name->value;
        }

        $key = $this->namedStory($name) ?? throw StoryNotFound::named($name);
        [$type, $verb] = explode('.', $key, 2);

        if ($type !== '*' && $object !== null
            && ($given = $object instanceof Model ? $object->getMorphClass() : (new Party)->getMorphClass()) !== $type) {
            throw StoryObjectMismatch::forObject($name, $key, $given);
        }

        if (($message = $this->messageFor($verb, $type === '*' ? null : $type)) !== null) {
            throw UnknownStory::boundToMessage($name, $message);
        }

        return $this->activity($verb, $object);
    }

    /**
     * Whether a story has this name, or every one of these: `Route::has()`'s
     * twin, behind `Story::has()`.
     *
     * @param  string|list<string>  $name
     */
    public function hasNamedStory(string|array $name): bool
    {
        $this->ensureStoriesCompiled();

        foreach ((array) $name as $value) {
            if (! array_key_exists($value, $this->storyNames)) {
                return false;
            }
        }

        return true;
    }

    /** The `type.verb` key a story name names, or null for a name nothing defined. */
    public function namedStory(string $name): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->storyNames[$name] ?? null;
    }

    /**
     * The name of the story a type and verb were published under, looked up
     * from the key and never stored: `order.confirm`, then `*.confirm` for
     * a name defined for every type. Null for a key nothing named, as an
     * unnamed route has no name.
     */
    public function storyNameFor(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        $names = array_flip($this->storyNames);

        return ($type === null ? null : $names["{$type}.{$verb}"] ?? null) ?? $names["*.{$verb}"] ?? null;
    }

    /**
     * Every story name, `name => type.verb`.
     *
     * @return array<string, string>
     */
    public function storyNames(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyNames;
    }

    /**
     * Compose and publish an activity synchronously in one call.
     * An anonymous activity has no actor; supplying both arguments is an error.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<int, Model>  $objects
     */
    public function record(
        string|FeedVerb|BackedEnum $verb,
        Model|string|null $object = null,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        Model|string|null $context = null,
        array $data = [],
        DateTimeInterface|string|null $publishedAt = null,
        iterable $objects = [],
        ?FeedThread $thread = null,
        Model|string|null $origin = null,
        Model|string|null $result = null,
        Model|string|null $instrument = null,
        ?FeedChange $change = null,
        bool $anonymous = false,
    ): Activity {
        if ($actor !== null && $anonymous) {
            throw new LogicException('record() was given an actor and anonymous: true; an anonymous activity has no actor.');
        }

        return $this->activity($verb, $object)
            ->when($objects !== [], fn (PendingActivity $a) => $a->objects($objects))
            ->when($actor !== null, fn (PendingActivity $a) => $a->actor($actor))
            ->target($target)
            ->context($context)
            ->origin($origin)
            ->result($result)
            ->instrument($instrument)
            ->when($data !== [], fn (PendingActivity $a) => $a->data($data))
            ->when($thread !== null, fn (PendingActivity $a) => $a->thread($thread))
            ->when($publishedAt !== null, fn (PendingActivity $a) => $a->publishedAt($publishedAt))
            ->when($change !== null, fn (PendingActivity $a) => $a->change($change))
            ->when($anonymous, fn (PendingActivity $a) => $a->anonymously())
            ->publish();
    }

    /**
     * Is the feed being written? Config decides unless a runtime toggle has
     * spoken for this process. The default is ON in every environment — see
     * config/storyfeed.php, `recording`.
     */
    public function isRecording(): bool
    {
        return $this->recording ?? (bool) config('storyfeed.recording.enabled', true);
    }

    /**
     * Stop writing the feed for the rest of this process. Every publish()
     * from here composes its Activity and hands it back unsaved; nothing
     * reaches the tables, nothing is dispatched, nothing throws.
     *
     * The same shape as Telescope's and Pulse's stopRecording(): process-
     * scoped, and overriding config rather than editing it.
     */
    public function stopRecording(): static
    {
        $this->recording = false;

        return $this;
    }

    /**
     * Resume writing the feed — or, in a suite muted through config, opt
     * this one process back in. This is the one-liner for a test that
     * asserts on the feed; `Testing\RecordsStories` is the same call as a
     * trait.
     */
    public function startRecording(): static
    {
        $this->recording = true;

        return $this;
    }

    /**
     * Run a callback with recording off, restoring whatever the state was
     * before — including when the callback throws.
     *
     *   Storyfeed::withoutRecording(fn () => $importer->run());
     *
     * Telescope's withoutRecording() and Pulse's ignore(), with the same
     * try/finally. The previous state is restored rather than recording
     * being switched back on: nesting one of these inside a muted suite must
     * leave the suite muted.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutRecording(callable $callback): mixed
    {
        return $this->recordingScope(false, $callback);
    }

    /**
     * Run a callback with recording ON, restoring the previous state after —
     * the inverse of withoutRecording(), for the single test in a muted file
     * that needs the rows to be real.
     *
     *   Storyfeed::recording(fn () => $this->post(route('orders.confirm', $order)));
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function recording(callable $callback): mixed
    {
        return $this->recordingScope(true, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function recordingScope(bool $recording, callable $callback): mixed
    {
        $previous = $this->recording;

        $this->recording = $recording;

        try {
            return $callback();
        } finally {
            $this->recording = $previous;
        }
    }

    /**
     * Attribute activities to an actor.
     *
     * With a callback, everything published inside it is attributed to that
     * actor — the scoped counterpart to `parties.fallback`, and what you
     * want inside a job or console command:
     *
     *   Storyfeed::actor('System', function () {
     *       Storyfeed::record('sync', object: $invoice);
     *   });
     *
     * Without one, it seeds a builder: Storyfeed::actor('System')->verb('sync').
     *
     * An explicit ->actor() still wins inside the scope, and the previous
     * resolver is always restored — including when the callback throws.
     * A job dispatched inside the scope runs as this actor too; a returned
     * PendingDispatch is dispatched inside the scope, and null comes back.
     *
     * @return ($callback is null ? PendingActivity : mixed)
     */
    public function actor(Model|string $actor, ?callable $callback = null): mixed
    {
        // An undeclared name in production: everything runs as it would
        // have without the scope.
        if (is_string($actor) && ! $this->admitsParty($actor, 'Storyfeed::actor()')) {
            return $callback === null ? $this->activity() : $this->withoutScope($callback);
        }

        $resolved = is_string($actor) ? $this->party($actor) : $actor;

        if ($callback === null) {
            return $this->activity()->actor($resolved);
        }

        $previous = [$this->actorResolver, $this->scopedActor, $this->queuedActor];

        $this->actorResolver = fn () => $resolved;
        $this->scopedActor = QueuedActor::identify($resolved);
        $this->queuedActor = null;

        try {
            $result = $callback();

            // `fn () => Job::dispatch()` hands back a PendingDispatch, which
            // dispatches when destroyed: after this scope, unless dropped here.
            if ($result instanceof PendingDispatch) {
                unset($result);

                return null;
            }

            return $result;
        } finally {
            [$this->actorResolver, $this->scopedActor, $this->queuedActor] = $previous;
        }
    }

    /**
     * Apply the context role inside a callback, or seed a builder without one.
     * Explicit context wins; the innermost scope is restored even on failure.
     * Returned PendingDispatch instances dispatch before the scope closes,
     * exactly as in actor(). There is no default context outside a scope.
     *
     * @return ($callback is null ? PendingActivity : mixed)
     */
    public function context(Model|string $context, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return $this->activity()->context($context);
        }

        $resolved = is_string($context) ? $this->party($context) : $context;
        $previous = [$this->contextModel, $this->scopedContext];
        $this->contextModel = $resolved;
        $this->scopedContext = QueuedContext::identify($resolved);

        try {
            return $this->withoutScope($callback);
        } finally {
            [$this->contextModel, $this->scopedContext] = $previous;
        }
    }

    /**
     * @internal
     *
     * @return array<string, mixed>|null
     */
    public function scopedContext(): ?array
    {
        return $this->scopedContext;
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $identity
     */
    public function enterQueuedContext(int $job, array $identity): void
    {
        $this->queuedContexts[$job] = [$this->contextModel, $this->scopedContext];
        $this->contextModel = null;
        $this->scopedContext = $identity;
    }

    /** @internal */
    public function leaveQueuedContext(int $job): void
    {
        if (isset($this->queuedContexts[$job])) {
            [$this->contextModel, $this->scopedContext] = $this->queuedContexts[$job];
            unset($this->queuedContexts[$job]);
        }
    }

    /** Apply the scope without requiring a queued model to still exist. */
    public function applyScopedContext(Activity $activity): ?Model
    {
        if ($this->contextModel !== null) {
            $activity->context()->associate($this->contextModel);

            return $this->contextModel;
        }

        $identity = $this->scopedContext;
        if ($identity === null || $identity === []) {
            return null;
        }

        // Party restoration uses the same name/key and recording rules as actor().
        $model = $this->restoreQueuedActor($identity);
        if (isset($identity['type'], $identity['id'])) {
            $activity->context_type = $identity['type'];
            $activity->context_id = $identity['id'];
        } elseif ($model !== null) {
            $activity->context()->associate($model);
        }

        return $model;
    }

    protected function withoutScope(callable $callback): mixed
    {
        $result = $callback();

        if ($result instanceof PendingDispatch) {
            unset($result);

            return null;
        }

        return $result;
    }

    /**
     * Declare the party names an actor may take: a verb's `->actor()` and
     * `Storyfeed::actor()`. Once any are declared, an undeclared name throws in
     * local and testing (`storyfeed.parties.strict`), and in production is
     * ignored, so the activity keeps the actor it would otherwise have had
     * and a name taken from a request never creates a party.
     * `storyfeed:doctor` names what was ignored.
     *
     *     Storyfeed::parties(['Stripe', 'Paddle']);
     *
     * Never declared, nothing is guarded, as before. Names match as party
     * keys do, slugged: 'Stripe' and 'stripe' are one party.
     *
     * @param  list<string>  $names
     */
    public function parties(array $names, bool $merge = true): static
    {
        $declared = [];

        foreach ($names as $name) {
            $declared[Str::slug($name)] = $name;
        }

        $this->declaredParties = $merge ? [...($this->declaredParties ?? []), ...$declared] : $declared;

        return $this;
    }

    /**
     * The declared party names, or null when none were declared.
     *
     * @return list<string>|null
     */
    public function declaredParties(): ?array
    {
        return $this->declaredParties === null ? null : array_values($this->declaredParties);
    }

    /**
     * Whether an actor may take this party name, throwing when strict.
     *
     * @internal
     */
    public function admitsParty(string $name, string $where): bool
    {
        if ($this->declaredParties === null || isset($this->declaredParties[Str::slug($name)])) {
            return true;
        }

        $strict = config('storyfeed.parties.strict');

        if ($strict ?? app()->environment('local', 'testing')) {
            throw UndeclaredParty::make($name, $where, array_values($this->declaredParties));
        }

        if ($this->isRecording() && ! isset($this->ignoredParties[$name])) {
            $this->ignoredParties[$name] = true;

            $this->recordIgnoredParty($name);
        }

        return false;
    }

    /** Keep an ignored name for the doctor. The fake writes nothing. */
    protected function recordIgnoredParty(string $name): void
    {
        IgnoredParties::record($name);
    }

    /**
     * The innermost scoped actor's identity, for QueuedActor to transport.
     *
     * @internal
     *
     * @return array<string, mixed>|null
     */
    public function scopedActor(): ?array
    {
        return $this->scopedActor;
    }

    /**
     * Run the rest of a queued job as the Storyfeed::actor() actor it was
     * dispatched under, until leaveQueuedScope() puts back what was there.
     *
     * @internal
     *
     * @param  array<string, mixed>  $identity
     */
    public function enterQueuedScope(int $job, array $identity): void
    {
        $this->queuedScopes[$job] = [$this->actorResolver, $this->scopedActor, $this->queuedActor];

        $this->actorResolver = fn () => $this->restoreQueuedActor($identity);
        $this->scopedActor = $identity;
        $this->queuedActor = $identity;
    }

    /** @internal */
    public function leaveQueuedScope(int $job): void
    {
        if (! isset($this->queuedScopes[$job])) {
            return;
        }

        [$this->actorResolver, $this->scopedActor, $this->queuedActor] = $this->queuedScopes[$job];

        unset($this->queuedScopes[$job]);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    protected function restoreQueuedActor(array $identity): ?Model
    {
        if (is_string($identity['party'] ?? null) && is_string($identity['key'] ?? null)) {
            $model = config('storyfeed.models.party', Party::class);

            if ($party = $model::find($identity['key'])) {
                return $party;
            }

            if ($this->isRecording()) {
                return $model::make($identity['party'], key: $identity['key']);
            }

            return $this->unsavedParty($identity['party'])->forceFill(['key' => $identity['key']]);
        }

        return is_string($identity['type'] ?? null) && (is_int($identity['id'] ?? null) || is_string($identity['id'] ?? null))
            ? MorphResolver::feedable($identity['type'], $identity['id'])
            : null;
    }

    /**
     * Begin composing a feed query, optionally through a named feed.
     *
     *   Storyfeed::feed()             the whole feed, unchanged
     *   Storyfeed::feed('customer')   the 'customer' feed's builder
     *
     * An unknown name throws rather than falling back to the unfiltered feed:
     * a named feed is how an audience's verb allowlist is declared, so
     * answering a typo with every verb you have is the one failure this must
     * not have.
     *
     * A feed class that takes constructor arguments also throws here, for the
     * same reason one level up: it has no unscoped form, and handing back a
     * builder missing the scope the class carries would be the fail-open case
     * the class exists to remove. Enter it through its constructor —
     * `CustomerFeed::make($order)`.
     */
    public function feed(?string $preset = null): FeedBuilder
    {
        if ($preset === null) {
            return new FeedBuilder;
        }

        return $this->feedDefinition($preset)->build();
    }

    /**
     * Register named feeds — an audience's scope and verb allowlist, declared
     * ONCE instead of at every call site.
     *
     *   Storyfeed::feeds([
     *       'customer' => CustomerFeed::class,                              // a class
     *       AdminFeed::class,                                              // name derived
     *       'kitchen' => fn (FeedBuilder $feed) => $feed->only(['order.*']), // a closure
     *   ]);
     *
     * The same register-once-at-boot shape as grammar(), axes(), verbs(),
     * icons() and checks(), one level up: those describe how an
     * activity READS, this describes which activities a surface is about.
     *
     * Both forms normalize into one FeedDefinition, exactly as a Story class
     * and an ad-hoc Stories\Verb do — so the closure stays first-class (a
     * two-line admin preset should not need a file) and nothing downstream can
     * tell which form declared a feed. Only a class can carry a SUBJECT: a
     * closure receives the builder at boot, before any subject exists, which is
     * the structural reason the scope half of the seam needed a class at all.
     *
     * The value of declaring here rather than app-side is that
     * `storyfeed:doctor` can see it — the FeedCoverage check turns a verb
     * nobody assigned to an audience into a CI failure instead of a leak six
     * months from now. An app-side allowlist is invisible to that.
     *
     * This is a query filter, not authorization and not row-level visibility;
     * see docs/feeds.md for what it deliberately does not do.
     *
     * @param  array<int|string, Closure|Feed|class-string<Feed>>  $feeds
     */
    public function feeds(array $feeds, bool $merge = true): static
    {
        $normalized = [];

        foreach ($feeds as $key => $value) {
            $definition = FeedDefinition::normalize($key, $value);

            $normalized[$definition->name] = $definition;
        }

        $this->feeds = $merge ? [...$this->feeds, ...$normalized] : $normalized;

        $this->feedKeys = [];

        foreach ($this->feeds as $name => $definition) {
            if ($definition->feedClass !== null) {
                // First registration wins — see feedNameFor() for why.
                $this->feedKeys[$definition->feedClass] ??= $name;
            }
        }

        return $this;
    }

    /**
     * Register app-owned permanent-source retirement policies. No scheduling.
     *
     * @param  list<class-string<FeedHealer>|FeedHealer>  $healers
     */
    public function healers(array $healers, bool $merge = true): static
    {
        $registered = [];

        foreach ($healers as $healer) {
            $healer = is_string($healer) ? app($healer) : $healer;

            if (! $healer instanceof FeedHealer || trim($healer->key()) === '') {
                throw new InvalidArgumentException('A healer must implement FeedHealer and have a non-empty key.');
            }

            $registered[$healer->key()] = $healer;
        }

        $this->healers = $merge ? array_replace($this->healers, $registered) : $registered;

        return $this;
    }

    /** @return array<string, FeedHealer> */
    public function registeredHealers(): array
    {
        return $this->healers;
    }

    /**
     * The key a Feed class is registered under, or null when it is not.
     *
     * ONE FEED, ONE IDENTITY. `'kitchen' => CustomerFeed::class` read through
     * `Storyfeed::feed('kitchen')` reports 'kitchen' to every resolver on the
     * page. Read through `CustomerFeed::make($order)` it used to report the
     * class-derived 'customer', so a `match` in feedMedia() was right on one
     * door and silently wrong on the other — no failure, just a link quietly
     * absent. The registered key wins because it is the one a human chose and
     * the one the doctor prints; the derived name is what a class is called
     * when nobody chose. Feed::name() consults this so both doors agree.
     *
     * REGISTERED TWICE. Nothing stops `'staff' => AdminFeed::class` and
     * `'ops' => AdminFeed::class` — one allowlist, two surfaces — and entering
     * by key still reports each key. Entered through the class there is no
     * key to prefer, so the FIRST registration (declaration order, which is
     * merge order across feeds() calls) is the class's canonical name. That
     * is deterministic and stated, not chosen at random; a class that needs
     * to be two surfaces from its own constructor is two classes.
     *
     * A reverse index rather than a scan: this runs once per feed build and
     * once per doctor label, so a scan would be affordable, but an index
     * costs nothing at registration and cannot get slower as feeds grow.
     *
     * @param  class-string<Feed>  $class
     */
    public function feedNameFor(string $class): ?string
    {
        return $this->feedKeys[$class] ?? null;
    }

    /**
     * The registered feeds as definitions, keyed by name.
     *
     * @return array<string, FeedDefinition>
     */
    public function registeredFeeds(): array
    {
        return $this->feeds;
    }

    /** @return list<string> */
    public function feedNames(): array
    {
        return array_keys($this->feeds);
    }

    /**
     * Resolve a feed by registered name, or by Feed class-string.
     *
     * The class-string form needs no registration, because the class is the
     * declaration. Registration is what makes doctor able to CHECK it, which is
     * why `make:feed` says so on every generate.
     */
    public function feedDefinition(string $preset): FeedDefinition
    {
        if (isset($this->feeds[$preset])) {
            return $this->feeds[$preset];
        }

        if (class_exists($preset) && is_a($preset, Feed::class, true)) {
            return FeedDefinition::fromFeed($preset);
        }

        throw UnknownFeed::named($preset, array_keys($this->feeds));
    }

    /**
     * Register headline grammar. Keys are "type.verb" (wildcards allowed:
     * "delivery.*", "*.confirm", "*.*"); values are template strings with
     * :actor/:object/:target/:context placeholders (and optional segments,
     * `[ with :target]`), FeedHeadline::trans() keys translated when the feed
     * is read, or closures receiving the Activity. A closure's result that
     * names a role token is a template; one without is finished text.
     *
     * Typed loosely on the KEY on purpose — see assertKeyed().
     *
     * @param  array<array-key, string|Closure|FeedHeadline>  $grammar
     */
    public function grammar(array $grammar, bool $merge = true): static
    {
        $this->assertKeyed($grammar, 'grammar', 'delivery.confirm', ':actor confirmed :object');

        $this->grammar = $merge ? [...$this->grammar, ...$grammar] : $grammar;

        return $this;
    }

    /**
     * Register singular actorless headlines — the sentence for an activity
     * with no actor. Keyed like grammar(), on the same type → verb ladder
     * (`order.confirm`, `order.*`, `*.confirm`, `*.*`), and tried before it
     * for actorless rows. A key with no dot is a verb, and means `*.verb`.
     * No aggregate forms.
     *
     * Strings are tokenizable templates and cannot name :actor or :actors.
     * Closures receive the Activity, as in grammar().
     *
     * @param  array<array-key, string|Closure|FeedHeadline>  $grammar
     */
    public function actorlessGrammar(array $grammar, bool $merge = true): static
    {
        $this->assertKeyed($grammar, 'actorlessGrammar', 'order.confirm', ':object was confirmed');

        $keyed = [];

        foreach ($grammar as $key => $entry) {
            if (is_string($entry) && str_contains($entry, ':actor')) {
                throw new InvalidArgumentException(
                    "Storyfeed::actorlessGrammar() template for `{$key}` must not contain :actor or :actors — actorless templates omit the actor.",
                );
            }

            $keyed[str_contains((string) $key, '.') ? (string) $key : "*.{$key}"] = $entry;
        }

        $this->actorlessGrammar = $merge ? [...$this->actorlessGrammar, ...$keyed] : $keyed;

        return $this;
    }

    /**
     * Resolve the actorless entry for an object type + verb.
     * Resolution order: type.verb → type.* → *.verb → *.*
     */
    public function actorlessTemplate(?string $type, string $verb): string|Closure|null
    {
        $this->ensureStoriesCompiled();

        return self::headlineEntry($this->resolve($this->actorlessGrammar, $type, $verb));
    }

    public function actorlessTemplateKey(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolveKey($this->actorlessGrammar, $type, $verb);
    }

    /** @return array<string, string|Closure|FeedHeadline> */
    public function registeredActorlessGrammar(): array
    {
        $this->ensureStoriesCompiled();

        return $this->actorlessGrammar;
    }

    /**
     * A registry value as the read path uses it: a FeedHeadline becomes its
     * template in the CURRENT locale, which is the reader's once the locale
     * middleware has run.
     */
    private static function headlineEntry(string|Closure|FeedHeadline|null $entry): string|Closure|null
    {
        return $entry instanceof FeedHeadline ? $entry->toTemplate() : $entry;
    }

    /**
     * Refuse a LIST where a keyed registry was meant.
     *
     * The failure this exists for is silent in the worst way: `grammar([':actor
     * confirmed :object'])` registers the integer 0 as the key, which resolves
     * for no (type, verb) pair that will ever be asked for, so every headline
     * stays null and the feed renders exactly as it did before anyone authored
     * anything. Nothing throws, nothing warns, and `storyfeed:doctor` reports
     * the grammar as missing — which is true, and points at the templates the
     * developer is looking straight at.
     *
     * Same shape as the bug in verbs(), where a list registered `0` as a verb.
     * One guard per registry rather than one shared abstraction over all of
     * them, because each needs to name its OWN key shape to be useful.
     *
     * @param  array<array-key, mixed>  $entries
     */
    private function assertKeyed(array $entries, string $method, string $key, string $value): void
    {
        foreach ($entries as $entryKey => $entryValue) {
            if (is_string($entryKey)) {
                continue;
            }

            $shown = is_string($entryValue) ? $entryValue : get_debug_type($entryValue);

            throw new InvalidArgumentException(
                "Storyfeed::{$method}() takes a MAP of key => value, not a list. Received [{$shown}] under "
                .'a numeric key, which resolves for nothing and fails silently. Write '
                ."Storyfeed::{$method}(['{$key}' => '{$value}'])"
                .' — keys are patterns, and wildcards (`type.*`, `*.verb`, `*.*`) are allowed.',
            );
        }
    }

    /**
     * Register grouping axes — replacing same-name axes, appending new
     * ones. Registration order is curation priority; `merge: false`
     * replaces the whole registry:
     *
     *   Storyfeed::axes([
     *       Axis::make('thread')->key('v:ca!:cid!:d')->eligibleWhenMembers(min: 3),
     *   ]);
     *
     * The four built-ins (actors, targets, object, repeat-as-fallback) are
     * seeded lazily; their thresholds read `grouping.policy` config.
     *
     * Registration order is priority; `before:` inserts new axes ahead of a
     * named axis, so a custom axis can outrank a built-in without the
     * consumer re-declaring the whole registry:
     *
     *   Storyfeed::axes([$scene], before: 'targets');
     *
     * Same-name replacement keeps the existing position (unless `before:`
     * moves it explicitly).
     *
     * @param  array<int, Axis>  $axes
     */
    public function axes(array $axes, bool $merge = true, ?string $before = null): static
    {
        $registry = $merge ? $this->registeredAxes() : [];

        if ($before !== null && ! isset($registry[$before])) {
            throw new InvalidArgumentException(
                "Cannot register axes before unknown axis [{$before}]. Registered: ".implode(', ', array_keys($registry)).'.',
            );
        }

        foreach ($axes as $axis) {
            if ($before === null && array_key_exists($axis->name, $registry)) {
                $registry[$axis->name] = $axis; // replace in place

                continue;
            }

            unset($registry[$axis->name]);

            if ($before === null) {
                $registry[$axis->name] = $axis;

                continue;
            }

            $position = array_search($before, array_keys($registry), true);

            $registry = [
                ...array_slice($registry, 0, (int) $position, preserve_keys: true),
                $axis->name => $axis,
                ...array_slice($registry, (int) $position, preserve_keys: true),
            ];
        }

        $this->axes = $registry;

        return $this;
    }

    /**
     * @return array<string, Axis> ordered — registration order is priority
     */
    public function registeredAxes(): array
    {
        return $this->axes ??= $this->defaultAxes();
    }

    public function axis(string $name): ?Axis
    {
        return $this->registeredAxes()[$name] ?? null;
    }

    /**
     * Which axes could apply to an activity filling exactly these roles.
     *
     * Row-backed and closure-recipe axes are excluded, because their
     * applicability is not derivable (see Axis::requiredRoles()). Excluding
     * them under-reports rather than over-reports, which is the safe direction
     * for a coverage tool: a gap it cannot see is better than a gap it
     * confidently denies.
     *
     * @param  array<int, string>  $filledRoles
     * @return array<int, string> axis names, in priority order
     */
    public function axesApplicableTo(array $filledRoles): array
    {
        return array_keys(array_filter(
            $this->registeredAxes(),
            fn (Axis $axis) => $axis->appliesToRoles(array_values($filledRoles)),
        ));
    }

    /**
     * Every (axis, verb) pair the app COULD produce, derived rather than
     * reasoned about.
     *
     * This replaces hand-partitioned coverage matrices. A consumer maintained
     * three of them, split by which verbs each axis can semantically produce,
     * with a comment conceding the reasoning "has already aged once" — and it
     * had: doctor found an `object.join` gap the written analysis said was
     * impossible.
     *
     * `$roleMap` is `verb => [roles seen filled]`. Supplied from a fake's
     * recorded activities or queried from the table. The honest limit: role-fill
     * observed from one run is a strictly better superset than hand-partitioning,
     * not a proof — a verb that has only ever been recorded without a target
     * looks like it can never have one.
     *
     * @param  array<string, array<int, string>>  $roleMap
     * @return array<int, array{0: string, 1: string}>
     */
    public function possibleAggregatePairs(array $roleMap): array
    {
        $pairs = [];

        foreach ($roleMap as $verb => $roles) {
            foreach ($this->axesApplicableTo($roles) as $axis) {
                $pairs[] = [$axis, (string) $verb];
            }
        }

        return array_values(array_unique($pairs, SORT_REGULAR));
    }

    /**
     * The non-fallback axis names, in priority order — the axes curation
     * can select and coverage tooling audits.
     *
     * @return array<int, string>
     */
    public function aggregateAxes(): array
    {
        return array_keys(array_filter(
            $this->registeredAxes(),
            fn (Axis $axis) => ! $axis->isFallback() && ! $axis->isRowBacked(),
        ));
    }

    /**
     * Buckets owned by row-backed state (batch windows, composite claims):
     * never emitted by the strategy, never stale-deleted, never competed
     * for by curation — docs/grouping.md, rows-vs-derivation.
     *
     * @return array<int, string>
     */
    public function rowBackedBuckets(): array
    {
        return array_keys(array_filter(
            $this->registeredAxes(),
            fn (Axis $axis) => $axis->isRowBacked(),
        ));
    }

    /**
     * Publish the activity a PublishesToFeed implementor describes.
     *
     * THE seam. Domain events reach it through one interface-registered
     * listener today; a Job, Mailable or Notification would reach the same
     * method from its own hook, with no change to the contract.
     *
     * A null return is a deliberate skip, not an error.
     */
    public function publishFor(PublishesToFeed $publisher): ?Activity
    {
        return $publisher->toFeedActivity()?->publish();
    }

    /**
     * Publish a message: `Storyfeed::publish(new OrderConfirmed($order))`,
     * as `Notification::send()` sends one. Null when its toFeedActivity()
     * says there is nothing to publish.
     *
     * A message that `implements ShouldQueue` is queued instead, as a queued
     * notification is, and this returns null, as `Mail::send()` does for a
     * queued mailable. Where it goes is the class's Queueable properties
     * (`$queue`, `$connection`, `$delay`, `$afterCommit`,
     * `$deleteWhenMissingModels`), over what the line binding it in
     * routes/feed.php declared. Its toFeedActivity() runs on the worker;
     * `published_at` is stamped now. See PublishQueuedStory.
     */
    public function publish(Story $story): ?Activity
    {
        PublishQueuedStory::ensureNotDebounced($story);

        if (! $story instanceof ShouldQueue) {
            return $this->publishNow($story);
        }

        // Unregistered is an error here, at the call, not on the worker.
        $this->storyVerb($story::class);

        $this->queueStory($story);

        return null;
    }

    /** Queue a message class that implements ShouldQueue. Overridden by the fake. */
    protected function queueStory(Story $story): void
    {
        if (! $this->isRecording()) {
            return;
        }

        PublishQueuedStory::dispatchFor($story, $this->storyQueueing($story::class));
    }

    /**
     * Publish a message now, whether or not it is queued, as
     * `Notification::sendNow()` does.
     */
    public function publishNow(Story $story): ?Activity
    {
        PublishQueuedStory::ensureNotDebounced($story);

        return $this->publishFor($story);
    }

    /**
     * Register what a Story facade line made.
     *
     * @internal Registration is routes/feed.php, through the Story facade.
     */
    public function addStory(Verb|PendingResource|BoundStory $story): static
    {
        $this->stories[] = $story;

        // Registering after a compile (a second provider, a test) must not be
        // silently ignored — and the memoized output is now stale.
        $this->storiesCompiled = false;
        $this->compiled = null;

        return $this;
    }

    /**
     * Compile registered stories into the registries.
     *
     * Deferred to App::booted() by the service provider so PROVIDER ORDERING IS
     * IRRELEVANT: compilation reads the axis registry (to validate group axes)
     * and the verb registry, and a definitions file that loads before an
     * app's axes() call would otherwise get a confusing "unknown axis" throw for a correct
     * configuration.
     *
     * Hand-written registrations WIN, whichever order they were made in. An
     * escape hatch you cannot use to override is not an escape hatch.
     */
    public function compileStories(): void
    {
        // Set BEFORE applying: the readers below are guarded by this flag and
        // applying calls verbs(), which calls a guarded reader. Without this
        // ordering the guard recurses forever.
        $this->storiesCompiled = true;

        $this->retractApplied();

        // A manifest may hold everything with nothing registered at boot:
        // the definitions file isn't loaded when cached.
        if ($this->stories === [] && $this->compiled === null) {
            return;
        }

        $compiled = $this->compiled ?? (new CompileStories)($this->storyDefinitions(), $this);

        // A manifest stores AS2 terms as strings; the registry holds enums.
        $compiled['objectTypes'] = array_map(
            fn (mixed $type) => $this->normalizeTerm($type, ObjectType::class),
            $compiled['objectTypes'],
        );

        $this->compiled = $compiled;

        $this->grammar = [...$compiled['grammar'], ...$this->grammar];
        $this->aggregateGrammar = [...$compiled['aggregateGrammar'], ...$this->aggregateGrammar];
        $this->actorlessGrammar = [...$compiled['actorlessGrammar'], ...$this->actorlessGrammar];
        $this->icons = [...$compiled['icons'], ...$this->icons];
        $this->glyphIntents = [...$compiled['glyphIntents'], ...$this->glyphIntents];
        $this->nouns = [...$compiled['nouns'], ...$this->nouns];
        $this->objectTypes = [...$compiled['objectTypes'], ...$this->objectTypes];
        $this->resolvedObjectTypes = [];
        $this->verbs = [...$compiled['verbs'], ...$this->verbs];

        foreach (array_keys($compiled['verbs']) as $verb) {
            $this->declaredVerbs[$verb] ??= true;
        }

        // `->missing()` has no hand-written array form: its registry is the
        // tombstone rules, which answer on the same ladder.
        $rules = app(TombstoneRules::class);

        foreach ($compiled['missing'] as $key => $roles) {
            $rules->set($key, $roles);
        }

        foreach ($compiled['forget'] as $key => $forget) {
            $rules->forget($key, $forget);
        }

        // No hand-written form either: these three are the Story layer's own.
        $this->missingGrammar = $compiled['missingGrammar'];
        $this->storyActors = $compiled['actors'];
        $this->storyRetention = $compiled['retention'];
        $this->storyKeepLatest = $compiled['keepLatest'];
        $this->storyPeriods = $compiled['periods'];
        $this->storyQueue = $compiled['queue'];
        $this->storyMiddleware = $compiled['middleware'];
        $this->storyActions = $compiled['actions'];
        $this->storyNames = $compiled['names'];
        $this->storyWheres = $compiled['wheres'];

        $this->applied = $compiled;
    }

    /**
     * Undo a previous compile's contribution before recompiling.
     *
     * Compiled entries are merged INTO the registries so the readers stay a
     * single array lookup. That means a second compile would otherwise find its
     * own earlier output sitting in `$this->grammar` and treat it as
     * hand-written — so a story whose headline CHANGED between compiles would
     * keep the old text, silently. Only entries still identical to what this
     * layer put there are withdrawn; anything a hand-written call has since
     * replaced is left exactly where it is.
     */
    protected function retractApplied(): void
    {
        if ($this->applied === null) {
            return;
        }

        foreach (CompileStories::REGISTRIES as $registry) {
            // Held by TombstoneRules, or replaced whole by the next compile.
            if (in_array($registry, ['missing', 'forget', 'retention', 'keepLatest', 'periods', 'queue', 'middleware', 'missingGrammar', 'actors', 'actions', 'names', 'wheres'], true)) {
                continue;
            }

            foreach ($this->applied[$registry] as $key => $value) {
                if (($this->{$registry}[$key] ?? null) === $value) {
                    unset($this->{$registry}[$key]);
                }
            }
        }

        $this->applied = null;
    }

    /**
     * The normalized definitions, in registration order.
     *
     * @return array<int, Verb>
     */
    public function storyDefinitions(): array
    {
        // Skipped at boot when a manifest is cached; tooling that needs the
        // definitions themselves (doctor, storyfeed:list, storyfeed:cache)
        // reads it here. A no-op once loaded, or when there is no file.
        app(DefinitionsFile::class)->load($this);

        $definitions = [];

        foreach ($this->stories as $story) {
            array_push($definitions, ...match (true) {
                $story instanceof Verb => [$story],
                $story instanceof PendingResource => $story->definitions(),
                $story instanceof BoundStory => [$story->definition()],
            });
        }

        return $definitions;
    }

    /**
     * The compiled arrays. Closure-free unless a definition authored a
     * closure headline; storyfeed:cache refuses those by key.
     *
     * @return Compiled
     */
    public function compiledStories(): array
    {
        return (new CompileStories)($this->storyDefinitions(), $this);
    }

    /**
     * Seed the compiled arrays from a cached manifest, skipping compilation.
     *
     * A manifest written before glyph intents existed (2026-09-09) has no
     * `glyphIntents` array. It is still a complete description of what those
     * stories compiled to — none of them carried an intent — so it is read as
     * an empty registry rather than rejected. ManifestStale still reports the
     * drift once a story gains one. The same holds for the registries added
     * with the Story facade (2026-09-23): actorless grammar, nouns and object
     * types.
     *
     * @param  array{grammar: array<string, string|Closure|FeedHeadline>, aggregateGrammar: array<string, string>, actorlessGrammar?: array<string, string|Closure|FeedHeadline>, icons: array<string, string>, glyphIntents?: array<string, string>, nouns?: array<string, string|FeedNoun>, objectTypes?: array<string, ObjectType|string>, verbs: array<string, mixed>, missing?: array<string, list<string>>, missingGrammar?: array<string, string|Closure|FeedHeadline>, forget?: array<string, bool>, retention?: array<string, string>, keepLatest?: array<string, array{per: list<string>, within: string|null}>, periods?: array<string, string>, queue?: array<string, array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}>, middleware?: array<string, array{middleware: list<string|Closure>, excluded: list<string>}>, actors?: array<string, string>, actions?: array<string, array{uses: string, request: bool, parts: array<string, string>|null}>, names?: array<string, string>, wheres?: array<string, array<string, list<string>>>}  $compiled
     * @param  list<string>  $stories  the Story classes the manifest was compiled from
     */
    public function useCompiledStories(array $compiled, array $stories = []): static
    {
        $this->cachedStories = $stories;

        $compiled['glyphIntents'] ??= [];
        $compiled['actorlessGrammar'] ??= [];
        $compiled['nouns'] ??= [];
        $compiled['objectTypes'] ??= [];
        $compiled['missing'] ??= [];
        $compiled['missingGrammar'] ??= [];
        $compiled['forget'] ??= [];
        $compiled['retention'] ??= [];
        $compiled['keepLatest'] ??= [];
        $compiled['periods'] ??= [];
        $compiled['queue'] ??= [];
        $compiled['middleware'] ??= [];
        $compiled['actors'] ??= [];
        $compiled['actions'] ??= [];
        $compiled['names'] ??= [];
        $compiled['wheres'] ??= [];

        $this->compiled = $compiled;
        $this->storiesCompiled = false;

        return $this;
    }

    /** @return list<Verb|PendingResource|BoundStory> */
    public function registeredStories(): array
    {
        return $this->stories;
    }

    /**
     * Is this Story class registered, here or in the cached manifest? The
     * definitions file isn't loaded when cached, so a class registered there
     * is only in the manifest.
     */
    public function hasStory(string $class): bool
    {
        if (in_array($class, $this->cachedStories, true)) {
            return true;
        }

        foreach ($this->stories as $entry) {
            if ($entry instanceof BoundStory && $entry->isMessage() && $entry->class === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The verb a message class publishes, as the compiled stories
     * know it — which is how a class bound in routes/feed.php, with no `$verb`
     * of its own, learns its verb, cached or not. Null for a class nothing
     * registered.
     */
    public function boundVerb(string $class): ?string
    {
        $this->ensureStoriesCompiled();

        foreach ($this->storyActions as $key => $action) {
            if ($action['uses'] === $class) {
                return explode('.', $key, 2)[1];
            }
        }

        return null;
    }

    /**
     * The verb a message class publishes, for its `$this->activity()`.
     *
     * Throws for a class nothing registered, rather than publishing a verb
     * nobody authored a headline for.
     *
     * @internal Use `$this->activity()` inside the class.
     */
    public function storyVerb(string $class): string
    {
        if (! $this->hasStory($class)) {
            throw UnknownStory::unregistered($class);
        }

        return $this->boundVerb($class) ?? throw StoryMisconfigured::missingVerb($class);
    }

    /**
     * The message class a verb is bound to, for this object type or, with
     * none, for any: `story()` refuses a name bound to it, so the class's
     * toFeedActivity() is never bypassed. Null for a verb only lines and
     * resource classes define.
     *
     * @return class-string<Story>|null
     *
     * @internal
     */
    public function messageFor(string|FeedVerb|BackedEnum $verb, ?string $type = null): ?string
    {
        $this->ensureStoriesCompiled();

        $verb = match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => trim($verb),
        };

        foreach ($this->storyActions as $key => $action) {
            [$keyType, $keyVerb] = explode('.', $key, 2);

            if ($keyVerb === $verb && ($type === null || $keyType === $type || $keyType === '*')
                && is_a($action['uses'], Story::class, true)) {
                return $action['uses'];
            }
        }

        return null;
    }

    /**
     * The keys (and scalar values) of every hand-written registry, so the
     * definitions file loader can tell whether the file wrote to one.
     *
     * @return array<string, array<array-key, mixed>>
     *
     * @internal
     */
    public function handWrittenRegistries(): array
    {
        $signature = fn (array $registry): array => array_map(
            fn (mixed $value) => match (true) {
                is_object($value) => spl_object_id($value),
                is_array($value) => count($value),
                default => $value,
            },
            $registry,
        );

        return [
            'grammar' => $signature($this->grammar),
            'aggregateGrammar' => $signature($this->aggregateGrammar),
            'actorlessGrammar' => $signature($this->actorlessGrammar),
            'icons' => $signature($this->icons),
            'glyphIntents' => $signature($this->glyphIntents),
            'nouns' => $signature($this->nouns),
            'objectTypes' => $signature($this->objectTypes),
            'verbs' => [...$signature($this->verbs), ...array_keys($this->declaredVerbs)],
            'axes' => $signature($this->axes ?? []),
            'feeds' => $signature($this->feeds),
            'healers' => $signature($this->healers),
            'checks' => $signature($this->checks ?? []),
            'bundleables' => $signature($this->bundleables),
        ];
    }

    /**
     * Compile on first read if boot has not done it yet. Console commands,
     * tests and the fake all reach the registries outside a normal request
     * lifecycle; this mirrors the existing `$this->axes ??= defaultAxes()`
     * laziness and costs one boolean per resolution.
     *
     * @internal
     */
    public function ensureStoriesCompiled(): void
    {
        if (! $this->storiesCompiled) {
            $this->compileStories();
        }
    }

    /**
     * Register additional health checks (see Contracts\DiagnosticCheck).
     *
     * @param  array<int, class-string<DiagnosticCheck>|DiagnosticCheck>  $checks
     */
    public function checks(array $checks, bool $merge = true): static
    {
        $this->checks = $merge ? [...($this->checks ?? Doctor::DEFAULT_CHECKS), ...$checks] : $checks;

        return $this;
    }

    /**
     * Audit feed health and registry coverage, as DATA.
     *
     * `storyfeed:doctor` is one formatter over this; an application can render
     * the same findings in its own UI instead of shelling out to Artisan and
     * scraping the CLI text (which is what the first consumer had to do).
     *
     * @param  array<int, string>  $only  check names; empty runs all
     */
    public function doctor(array $only = []): Report
    {
        return (new Doctor($this->resolvedChecks()))->run($this, $only);
    }

    /**
     * Check names available to `--only=`, including app-registered ones.
     *
     * @return array<int, string>
     */
    public function checkNames(): array
    {
        return array_map(fn (DiagnosticCheck $check) => $check->name(), $this->resolvedChecks());
    }

    /** @return list<DiagnosticCheck> */
    protected function resolvedChecks(): array
    {
        return array_values(array_map(
            fn (string|DiagnosticCheck $check) => is_string($check) ? app($check) : $check,
            $this->checks ?? Doctor::DEFAULT_CHECKS,
        ));
    }

    /**
     * Designate morph aliases as bundleable (see Contracts\Bundleable).
     *
     * @param  array<int, string>  $aliases
     */
    public function bundleables(array $aliases, bool $merge = true): static
    {
        $designated = array_fill_keys($aliases, true);

        $this->bundleables = $merge ? [...$this->bundleables, ...$designated] : $designated;

        return $this;
    }

    /**
     * Registry wins; the Bundleable marker interface is the model-side
     * declaration for first-party models.
     */
    public function isBundleable(?string $alias): bool
    {
        if ($alias === null) {
            return false;
        }

        if (isset($this->bundleables[$alias])) {
            return true;
        }

        $class = MorphResolver::classFor($alias);

        return $class !== null && is_a($class, Bundleable::class, true);
    }

    public function fallbackAxis(): ?Axis
    {
        foreach ($this->registeredAxes() as $axis) {
            if ($axis->isFallback()) {
                return $axis;
            }
        }

        return null;
    }

    /**
     * The headline tokens an aggregate template may safely use for an
     * axis — derived from the axis's recipe (homogeneity by construction).
     * `'*'` returns the intersection across all axes, since wildcard
     * grammar keys serve every axis.
     *
     * @return array<int, string>|null null when the axis is unregistered
     */
    public function aggregateTokens(string $axis): ?array
    {
        if ($axis === '*') {
            $sets = array_map(
                fn (Axis $a) => $a->pinnedTokens(),
                array_values($this->registeredAxes()),
            );

            return $sets === [] ? [] : array_values(array_intersect(...$sets));
        }

        return $this->axis($axis)?->pinnedTokens();
    }

    /**
     * Does an axis pin the KIND of a role even where it leaves the role's
     * identity unpinned? Unregistered axes answer false — see
     * Axis::pinsType().
     */
    public function pinsType(string $axis, string $role): bool
    {
        return $this->axis($axis)?->pinsType($role) ?? false;
    }

    /**
     * Register the plural forms of the things a role holds, so a group can
     * say "7 clauses" where its axis leaves the role unpinned:
     *
     *   Storyfeed::nouns([
     *       'clause' => 'clause|clauses',        // per TYPE
     *       'document.upload' => 'file|files',   // per (type, verb)
     *       'delivery' => FeedNoun::trans('nouns.delivery'),
     *   ]);
     *
     * Keys are MORPH ALIASES — the value stored in `object_type` — never
     * class names, matching how every other comparison in the package is
     * made. Lookup runs type.verb, then type, then the `*` entry, then the
     * generic noun; there are no `type.*` or `*.verb` wildcards, because a
     * noun describes a KIND of thing and the verb is only ever a refinement
     * of it ("uploads of a document are files").
     *
     * A type with no noun registered still renders, as "7 items". That is
     * deliberate: the screen belongs to the reader, and the nagging belongs
     * on the developer's terminal.
     *
     * BOTH FORMS ARE REQUIRED — 'terms sheet|terms sheets'. The core never
     * inflects, so a single-form value throws here rather than rendering
     * "7 terms sheet" to somebody's customer. See FeedNoun::of().
     *
     * Typed loosely on the KEY on purpose — see assertKeyed().
     *
     * @param  array<array-key, string|FeedNoun>  $nouns
     */
    public function nouns(array $nouns, bool $merge = true): static
    {
        $this->assertKeyed($nouns, 'nouns', 'clause', 'clause|clauses');

        // Validate at REGISTRATION, not at render: a one-form noun is an
        // authoring mistake, and the core never inflects to cover it.
        foreach ($nouns as $noun) {
            if (is_string($noun)) {
                FeedNoun::of($noun);
            }
        }

        $this->nouns = $merge ? [...$this->nouns, ...$nouns] : $nouns;

        return $this;
    }

    /**
     * The noun registered for a type (optionally refined by verb), or null
     * when none is — which the caller renders as the generic noun.
     */
    public function noun(?string $type, string $verb): string|FeedNoun|null
    {
        $this->ensureStoriesCompiled();

        if ($type !== null && array_key_exists("{$type}.{$verb}", $this->nouns)) {
            return $this->nouns["{$type}.{$verb}"];
        }

        if ($type !== null && array_key_exists($type, $this->nouns)) {
            return $this->nouns[$type];
        }

        return $this->nouns['*'] ?? null;
    }

    /** @return array<string, string|FeedNoun> */
    public function registeredNouns(): array
    {
        $this->ensureStoriesCompiled();

        return $this->nouns;
    }

    /**
     * @return array<string, Axis>
     */
    protected function defaultAxes(): array
    {
        $policy = config('storyfeed.grouping.policy', []);

        $axes = [
            Axis::make('actors')
                ->key('v:ta!:tid:d')
                ->eligibleWhenDistinct('actor', min: (int) ($policy['min_actors'] ?? 3)),
            Axis::make('targets')
                ->key('aa!:aid:v:d')
                ->eligibleWhenDistinct('target', min: (int) ($policy['min_targets'] ?? 2))
                ->eligibleWhenMembers(min: (int) ($policy['min_target_members'] ?? 3)),
            Axis::make('object')
                ->key('aa:aid:v:oa!:oid!:d')
                ->eligibleWhenMembers(min: (int) ($policy['min_object_members'] ?? 2)),
            Axis::make('repeat')
                ->key('aa:aid:v:oa:ta:tid:d')
                ->fallback(),
            // Row-backed buckets: composite claims (authored/auto-bundled
            // collection stories — pins derived from what a composite
            // shares: one actor, one target, one context, MANY objects)
            // and batch windows (infrastructure, no feed effect, no pins).
            Axis::make('composite')
                ->rowBacked()
                ->pins(':actor', ':target', ':context'),
            Axis::make('batch')
                ->rowBacked(),
        ];

        return array_combine(array_column($axes, 'name'), $axes);
    }

    /**
     * Register aggregate headline grammar for GROUP nodes. Keys are
     * "axis.verb" (wildcards allowed: "actors.*", "*.upload", "*.*"):
     *
     *   Storyfeed::aggregateGrammar([
     *       'actors.upload' => ':actors uploaded :count files to :target',
     *       'targets.comment' => ':actor commented on :count projects',
     *   ]);
     *
     * Templates add the aggregate tokens :actors, :count and :others to the
     * standard role tokens (docs/payload.md). Without an entry a group falls
     * back to the singular grammar of its head member — which is why a
     * multi-actor group reads "Sally uploaded a file" until this is authored.
     *
     * Typed loosely on the KEY on purpose — see assertKeyed().
     *
     * @param  array<array-key, string|Closure|FeedHeadline>  $grammar
     */
    public function aggregateGrammar(array $grammar, bool $merge = true): static
    {
        $this->assertKeyed($grammar, 'aggregateGrammar', 'actors.upload', ':actors uploaded :count files');

        $this->aggregateGrammar = $merge ? [...$this->aggregateGrammar, ...$grammar] : $grammar;

        return $this;
    }

    /**
     * Register icons, keyed like grammar ("type.verb", wildcards allowed).
     *
     * Typed loosely on the KEY on purpose — see assertKeyed().
     *
     * @param  array<array-key, string>  $icons
     */
    public function icons(array $icons, bool $merge = true): static
    {
        $this->assertKeyed($icons, 'icons', 'delivery.confirm', 'bi-truck');

        $this->icons = $merge ? [...$this->icons, ...$icons] : $icons;

        return $this;
    }

    /**
     * Register glyph intents, keyed like icons ("type.verb", wildcards
     * allowed) and resolved on the same ladder — but in a registry of their
     * own, so an app says `'*.finalize' => 'success'` ONCE and it holds for
     * every finalize whose token is registered per type. Folded into the icon
     * value, the more specific token entry would shadow the wildcard intent
     * and the intent would have to be repeated at every rung.
     *
     * The value is a free-form app-owned string, the same posture as the
     * token and as verbs: core ships no vocabulary of intents and no colours.
     * A renderer maps whatever the app chose onto its own palette; unknown
     * intents are passed through, never dropped. See docs/payload.md,
     * `glyph_intent`.
     *
     * @param  array<array-key, string>  $intents
     */
    public function glyphIntents(array $intents, bool $merge = true): static
    {
        $this->assertKeyed($intents, 'glyphIntents', '*.finalize', 'success');

        $this->glyphIntents = $merge ? [...$this->glyphIntents, ...$intents] : $intents;

        return $this;
    }

    /**
     * Register verb → AS2.0 activity type mappings.
     *
     * Accepts either a map, or the class-string of a backed enum
     * implementing FeedVerb — in which case its cases are expanded:
     *
     *   Storyfeed::verbs(ActivityVerb::class);
     *   Storyfeed::verbs(['confirm' => ActivityType::Update]);
     *
     * Unrecognized type strings are preserved verbatim (extension types
     * must survive round-tripping).
     *
     * Typed on the KEY loosely on purpose: this receives whatever the app passed, and validating it is what the loop
     * below is for. Declaring `array<string, …>` claimed a guarantee PHP does
     * not enforce, and the cost of the lie was a list silently registering the
     * integer 0 as a verb.
     *
     * @param  array<array-key, ActivityType|string>|class-string  $verbs
     */
    public function verbs(array|string $verbs, bool $merge = true): static
    {
        $resolved = is_string($verbs) ? $this->expandVerbEnum($verbs) : $verbs;

        $normalized = [];

        foreach ($resolved as $verb => $type) {
            // A LIST instead of a map is the one input that fails silently and
            // badly: `['order.placed']` registers the integer 0 as the verb and
            // the verb string as its activity type — which normalizeTerm then
            // preserves verbatim, because extension types must round-trip. The
            // app now has a vocabulary doctor believes in and `verbs.strict`
            // rejects every real verb against. Loud beats plausible.
            if (! is_string($verb)) {
                $shown = is_string($type) ? $type : get_debug_type($type);

                throw new InvalidArgumentException(
                    'Storyfeed::verbs() takes a MAP of verb => activity type, not a list. '
                    ."Received [{$shown}] under a numeric key; write "
                    ."Storyfeed::verbs(['{$shown}' => ActivityType::Update]) — or pass the class-string of "
                    .'a backed enum implementing FeedVerb and let it declare its own mappings.',
                );
            }

            $normalized[$verb] = $this->normalizeTerm($type, ActivityType::class);
        }

        $this->verbs = $merge ? [...$this->verbs, ...$normalized] : $normalized;

        $declared = array_fill_keys(array_keys($normalized), true);

        $this->declaredVerbs = $merge ? [...$this->declaredVerbs, ...$declared] : $declared;

        return $this;
    }

    /**
     * Whether the app explicitly registered this verb (as opposed to it
     * resolving through the shipped defaults).
     */
    public function declaredVerb(string $verb): bool
    {
        $this->ensureStoriesCompiled();

        return isset($this->declaredVerbs[$verb]);
    }

    /**
     * Register morph alias → AS2.0 object type mappings.
     *
     * Typed loosely on the KEY on purpose — see assertKeyed().
     *
     * @param  array<array-key, ObjectType|string>  $objectTypes
     */
    public function objectTypes(array $objectTypes, bool $merge = true): static
    {
        $this->assertKeyed($objectTypes, 'objectTypes', 'delivery', 'Document');

        $normalized = [];

        foreach ($objectTypes as $alias => $type) {
            $normalized[$alias] = $this->normalizeTerm($type, ObjectType::class);
        }

        $this->objectTypes = $merge ? [...$this->objectTypes, ...$normalized] : $normalized;

        $this->resolvedObjectTypes = [];

        return $this;
    }

    /**
     * Register the verb vocabulary from one or more FeedVerb enums.
     *
     * @return array<string, ActivityType|string>
     */
    protected function expandVerbEnum(string $enum): array
    {
        // Returning [] here registered NOTHING, silently, for the two mistakes
        // this form invites: a plain backed enum that forgot `implements
        // FeedVerb` / `use AsFeedVerb`, and a class-string that does not exist
        // (a stale import, a renamed enum). The app then has no vocabulary at
        // all — and the symptom is `verbs.undeclared` from doctor, which reads
        // as "you have not declared a vocabulary yet" to someone who just did.
        if (! class_exists($enum) && ! interface_exists($enum)) {
            throw new InvalidArgumentException(
                "Storyfeed::verbs() was given [{$enum}], which is not a class. Pass a MAP of "
                .'verb => activity type, or the class-string of a backed enum implementing FeedVerb.',
            );
        }

        if (! is_a($enum, FeedVerb::class, true) || ! is_a($enum, BackedEnum::class, true)) {
            throw new InvalidArgumentException(
                "Storyfeed::verbs() was given [{$enum}], which is not a backed enum implementing "
                .'Storyfeed\Contracts\FeedVerb. Add `implements FeedVerb` and `use AsFeedVerb` to it, or '
                ."register the verbs as a map: Storyfeed::verbs(['confirm' => ActivityType::Update]).",
            );
        }

        $map = [];

        foreach ($enum::cases() as $case) {
            /** @var FeedVerb $case */
            if (($type = $case->activityType()) !== null) {
                $map[$case->verb()] = $type;
            } else {
                $map[$case->verb()] = self::DEFAULT_VERBS[$case->verb()] ?? CoreType::Activity->value;
            }
        }

        return $map;
    }

    /**
     * Resolve the grammar entry for an object type + verb.
     * Resolution order: type.verb → type.* → *.verb → *.*
     */
    public function template(?string $type, string $verb): string|Closure|null
    {
        $this->ensureStoriesCompiled();

        return self::headlineEntry($this->resolve($this->grammar, $type, $verb));
    }

    /**
     * Resolve the aggregate grammar entry for a group's axis + verb.
     * Resolution order: axis.verb → axis.* → *.verb → *.*
     */
    public function aggregateTemplate(?string $axis, string $verb, ?string $objectType = null): string|Closure|null
    {
        $this->ensureStoriesCompiled();

        return self::headlineEntry($this->aggregateGrammar[self::qualifiedKey($axis, $verb, $objectType)]
            ?? $this->resolve($this->aggregateGrammar, $axis, $verb));
    }

    /**
     * The three-segment aggregate key, `axis.objectType.verb`.
     *
     * WHY IT EXISTS. Singular grammar is keyed `objectType.verb`, so two acts
     * that share a verb stay apart by their object. Aggregate grammar is keyed
     * `axis.verb` and has no such second dimension, so collapsing an app's
     * verbs onto a shared vocabulary makes `repeat.update` catch every update
     * there is — and the entry that used to name one act now narrates all of
     * them. Found converting a consumer's doctrine verbs: two families that
     * read as different sentences became one aggregate key.
     *
     * ONLY SAFE WHEN THE AXIS PINS THE OBJECT TYPE, which is the caller's
     * check (`Axis::pinsType('object')`). Otherwise the members of one group
     * may hold different object types and the key would name whichever
     * happened to be first.
     *
     * Tried BEFORE the two-segment order and never instead of it: every
     * existing key keeps its meaning, and an app that never writes a
     * three-segment key never sees a difference.
     */
    private static function qualifiedKey(?string $axis, string $verb, ?string $objectType): string
    {
        return $objectType === null ? "\0" : "{$axis}.{$objectType}.{$verb}";
    }

    /**
     * Resolve the icon for an object type + verb (same order as grammar).
     */
    public function icon(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->icons, $type, $verb);
    }

    /**
     * Resolve the glyph intent for an object type + verb (same order as
     * the icon, independently of it). Null for every pair no intent was
     * registered for — which is every app that has not opted in.
     */
    public function glyphIntent(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->glyphIntents, $type, $verb);
    }

    /**
     * The AS2.0 activity type for a verb: an enum when known, a raw string
     * for extension types, null when unmapped.
     */
    public function activityType(string $verb): ActivityType|string|null
    {
        $this->ensureStoriesCompiled();

        return $this->verbs[$verb] ?? null;
    }

    /**
     * The wire value for a verb's AS2.0 type. Always returns something —
     * unmapped verbs fall back to the base `Activity` type.
     */
    public function activityTypeValue(string $verb): string
    {
        $type = $this->activityType($verb);

        return $type instanceof ActivityType ? $type->value : ($type ?? CoreType::Activity->value);
    }

    /**
     * The AS2.0 object type for a morph alias. The registry wins; a model
     * may declare its own via HasActivityStreamsType.
     */
    public function objectType(string $alias): ObjectType|string|null
    {
        $this->ensureStoriesCompiled();

        if (isset($this->objectTypes[$alias])) {
            return $this->objectTypes[$alias];
        }

        return $this->resolvedObjectTypes[$alias] ??= $this->objectTypeFromModel($alias);
    }

    /**
     * The wire value for an entity's AS2.0 type, falling back to `Object`.
     */
    public function objectTypeValue(string $alias): string
    {
        $type = $this->objectType($alias);

        return $type instanceof ObjectType ? $type->value : ($type ?? ObjectType::Object->value);
    }

    /** @return array<string, string|Closure|FeedHeadline> */
    public function registeredGrammar(): array
    {
        $this->ensureStoriesCompiled();

        return $this->grammar;
    }

    /** @return array<string, string|Closure|FeedHeadline> */
    public function registeredAggregateGrammar(): array
    {
        $this->ensureStoriesCompiled();

        return $this->aggregateGrammar;
    }

    /** @return array<string, string> */
    public function registeredIcons(): array
    {
        $this->ensureStoriesCompiled();

        return $this->icons;
    }

    /** @return array<string, string> */
    public function registeredGlyphIntents(): array
    {
        $this->ensureStoriesCompiled();

        return $this->glyphIntents;
    }

    /** @return array<string, ActivityType|string> */
    public function registeredVerbs(): array
    {
        $this->ensureStoriesCompiled();

        return $this->verbs;
    }

    /** @return array<string, ObjectType|string> */
    public function registeredObjectTypes(): array
    {
        $this->ensureStoriesCompiled();

        return $this->objectTypes;
    }

    /**
     * Treat a model you don't own as Feedable, from a service provider:
     *
     *     Storyfeed::feedable(Media::class)
     *         ->toFeedUsing(fn (Media $media, FeedEntity $entity) => $entity->label($media->name))
     *         ->feedMediaUsing(fn (FeedContext $context, FeedMedia $media) => $media->url(...));
     *
     * Its saves refresh its snapshot and its deletes reach the feed, as a
     * model using InteractsWithFeed does. A class that already implements
     * Feedable can't also be registered.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return FeedableRegistration<TModel>
     */
    public function feedable(string $class): FeedableRegistration
    {
        return app(Feedables::class)->register($class);
    }

    /**
     * Tombstone rows deleted without model events, straight after the bulk
     * delete:
     *
     *     Order::query()->whereIn('id', $ids)->delete();
     *     Storyfeed::tombstone(Order::class, $ids);
     *
     * Every activity naming one of them is repointed to its tombstone, as a
     * single `$order->delete()` would have done. A key whose row still exists
     * (and isn't trashed) is skipped. `$type` is a model class or a morph
     * alias; an alias for a non-model Feedable works too, and its tombstones
     * are never restorable. Without this call the trickle finds the deletions
     * later, and marks their time as approximate.
     *
     * @param  class-string<Model>|string  $type
     * @param  iterable<int|string>|int|string  $ids
     * @return list<FeedTombstone>
     */
    public function tombstone(string $type, iterable|int|string $ids): array
    {
        $alias = class_exists($type) && is_a($type, Model::class, true)
            ? (new $type)->getMorphClass()
            : $type;

        return (new TombstoneEntity)->missing($alias, is_iterable($ids) ? $ids : [$ids]);
    }

    /**
     * Guess the label of every model whose feed code sets none, app-wide:
     *
     *     Storyfeed::guessFeedLabelsUsing(fn (Model $model) => $model->reference);
     *
     * Return null to fall through to the default ladder (see
     * InteractsWithFeed::guessFeedLabel()). A model that overrides
     * guessFeedLabel() itself isn't asked.
     *
     * @param  (Closure(Model): ?string)|null  $guesser
     */
    public function guessFeedLabelsUsing(?Closure $guesser): static
    {
        app(Feedables::class)->guessLabelsUsing($guesser);

        return $this;
    }

    /**
     * Override how the default actor is resolved at publish time.
     */
    public function resolveActorUsing(Closure $resolver): void
    {
        $this->actorResolver = $resolver;
    }

    /**
     * Resolve the default actor for activities published without one.
     *
     * Precedence: runtime closure → configured resolver → authenticated user
     * → configured fallback party (for jobs and console commands) → null.
     * Null means genuinely anonymous: the actor is unknown, not a system.
     */
    public function resolveActor(): ?Model
    {
        if ($this->actorResolver) {
            return ($this->actorResolver)() ?? $this->fallbackParty();
        }

        if ($resolver = config('storyfeed.actor_resolver')) {
            return app($resolver)() ?? $this->fallbackParty();
        }

        return Auth::user() ?? $this->fallbackParty();
    }

    /**
     * The scope's actor, applied ahead of story middleware: `Storyfeed::actor()`
     * in this process, or the one a job was dispatched under. Null, touching
     * nothing, outside such a scope; the rest of the ladder waits for
     * applyDefaultActor(), after the middleware.
     *
     * @internal
     */
    public function applyScopedActor(Activity $activity): ?Model
    {
        if ($this->queuedActor === null && $this->scopedActor === null) {
            return null;
        }

        return $this->applyDefaultActor($activity);
    }

    /**
     * Apply a transported identity without requiring its model to still exist.
     * Explicit actors and anonymity are guarded by the callers. Application
     * resolvers retain authority over a transported auth user, but not over a
     * Storyfeed::actor() actor, which outranks them at dispatch too; context
     * precedes worker auth and Party fallback.
     */
    public function applyDefaultActor(Activity $activity): ?Model
    {
        $identity = $this->queuedActor;

        // A verb's own actor ranks below Storyfeed::actor(), in this process or
        // carried into a job, and above everything else.
        if ($identity === null && $this->scopedActor === null && ($actor = $this->verbActor($activity)) !== null) {
            if ($actor instanceof Model) {
                $activity->actor()->associate($actor);

                return $actor;
            }

            // A model an action chose at dispatch, deleted since or not.
            $identity = $actor;
        }

        // A scoped identity only counts inside its job's scope (above).
        if ($identity === null && ! $this->actorResolver && ! config('storyfeed.actor_resolver')) {
            $identity = app(Repository::class)->getHidden(QueuedActor::KEY);
            $identity = QueuedActor::isScoped($identity) ? null : $identity;
        }

        if (is_array($identity) && is_string($identity['type'] ?? null)
            && (is_int($identity['id'] ?? null) || is_string($identity['id'] ?? null))) {
            $activity->actor_type = $identity['type'];
            $activity->actor_id = $identity['id'];

            return MorphResolver::feedable($identity['type'], $identity['id']);
        }

        $actor = $this->resolveActor();
        if ($actor !== null) {
            $activity->actor()->associate($actor);
        }

        return $actor;
    }

    /**
     * The actor a verb chooses: an action that takes the request, run for
     * this publish or carried from dispatch into a job, or else a fixed
     * `->actor()` name. A model carried from dispatch comes back as its
     * morph alias and key. Null says nothing.
     *
     * @return Model|array{type: string, id: int|string}|null
     */
    protected function verbActor(Activity $activity): Model|array|null
    {
        $this->ensureStoriesCompiled();

        if ($this->storyActions === [] && $this->storyActors === []) {
            return null;
        }

        $type = $activity->object_type;
        $verb = (string) $activity->verb;
        $key = $type === null ? "*.{$verb}" : "{$type}.{$verb}";
        $action = $this->storyActions[$key] ?? null;

        if ($action !== null && $action['request']) {
            $carried = app(Repository::class)->getHidden(QueuedActor::ACTIONS);
            $where = $action['uses'];

            // In a job dispatched during a request, what the action chose
            // there, and nothing if it chose nothing: this request is blank.
            if (is_array($carried)) {
                $carried = $carried[$key] ?? null;

                return match (true) {
                    is_string($carried['party'] ?? null) && is_string($carried['key'] ?? null) => $this->restoreQueuedActor($carried),
                    is_string($carried['party'] ?? null) => $this->admitsParty($carried['party'], $where) ? $this->party($carried['party']) : null,
                    is_string($carried['type'] ?? null) && (is_int($carried['id'] ?? null) || is_string($carried['id'] ?? null)) => ['type' => $carried['type'], 'id' => $carried['id']],
                    default => null,
                };
            }

            $actor = $this->runAction($key, $action);
        } else {
            $actor = $this->resolve($this->storyActors, $type, $verb);
            $where = "The actor of [{$key}]";
        }

        if (is_string($actor)) {
            return $this->admitsParty($actor, $where) ? $this->party($actor) : null;
        }

        return $actor instanceof Model ? $actor : null;
    }

    /**
     * What each action that takes the request chooses as its actor for this
     * request, for QueuedActor to carry into a job dispatched now: on the
     * worker the request is blank. Each runs once per request, however many
     * jobs it dispatches, and every one runs, since which verbs a job will
     * publish can't be known. Null when there are none to run.
     *
     * Results only: a party name (a Party model by name and key), or a model
     * by morph alias and key. An action that chooses nothing is left out,
     * never carried as a null actor, and so is one that throws, which never
     * fails the dispatch and is kept for `storyfeed:doctor`. The worker
     * applies the declared-parties guard to a carried name, as a publish
     * here would.
     *
     * @internal
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function carriedActions(): ?array
    {
        // Misconfigured stories throw at publish, never at dispatch.
        try {
            $this->ensureStoriesCompiled();
        } catch (StoryMisconfigured) {
            return null;
        }

        $actions = array_filter($this->storyActions, fn (array $action) => $action['request']);
        $request = app()->bound('request') ? app('request') : null;

        if ($actions === [] || ! $request instanceof Request) {
            return null;
        }

        $this->carriedActions ??= new WeakMap;

        return $this->carriedActions[$request] ??= $this->runCarriedActions($actions);
    }

    /**
     * @param  array<string, array{uses: string, request: bool, parts: array<string, string>|null}>  $actions
     * @return array<string, array<string, mixed>>
     */
    protected function runCarriedActions(array $actions): array
    {
        $carried = [];

        foreach ($actions as $key => $action) {
            try {
                $actor = $this->runAction($key, $action);
            } catch (Throwable $e) {
                $this->recordFailedCarry($action['uses'], $e);

                continue;
            }

            if (is_string($actor) && trim($actor) !== '') {
                $carried[$key] = ['party' => $actor];
            } elseif ($actor instanceof Party) {
                $carried[$key] = ['party' => $actor->name, 'key' => $actor->key];
            } elseif ($actor instanceof Model && $actor->getKey() !== null) {
                $carried[$key] = ['type' => $actor->getMorphClass(), 'id' => $actor->getKey()];
            }
        }

        return $carried;
    }

    /** Keep an action that threw at dispatch for the doctor, once a process. */
    protected function recordFailedCarry(string $uses, Throwable $e): void
    {
        if (isset($this->failedCarries[$uses])) {
            return;
        }

        $this->failedCarries[$uses] = true;

        report($e);

        if ($this->isRecording()) {
            CarryFailures::record($uses, $e);
        }
    }

    /**
     * Run an action that takes the request, for the publish happening now,
     * and return the actor it chose. What else it returns must match what it
     * returned when stories compiled: strict mode throws where it doesn't,
     * and elsewhere the compiled definition wins and it is reported once.
     *
     * @param  array{uses: string, request: bool, parts: array<string, string>|null}  $action
     */
    protected function runAction(string $key, array $action): Model|string|null
    {
        [$class, $method] = explode('@', $action['uses'], 2);
        $request = app()->bound('request') ? app('request') : null;

        /** @var class-string $class */
        $definition = ResourceClass::run($class, $method, Verb::make($key, $action['uses']), $request instanceof Request ? $request : null);

        $parts = $definition->compiledParts();

        foreach ($action['parts'] ?? [] as $part => $compiled) {
            if (($parts[$part] ?? null) === $compiled) {
                continue;
            }

            $varies = StoryMisconfigured::requestVaries($action['uses'], $part);

            // grammar.strict: what drifted is what the feed reads.
            if (config('storyfeed.grammar.strict') ?? app()->environment('local', 'testing')) {
                throw $varies;
            }

            if (! isset($this->varyingActions[$action['uses']])) {
                $this->varyingActions[$action['uses']] = true;

                report($varies);
            }

            break;
        }

        return $definition->actorGiven();
    }

    /**
     * What a redundant activity reads as, if its verb says: the
     * `->missingHeadline()` on the type → verb ladder.
     */
    public function missingTemplate(?string $type, string $verb): string|Closure|null
    {
        $this->ensureStoriesCompiled();

        return self::headlineEntry($this->resolve($this->missingGrammar, $type, $verb));
    }

    /**
     * The action each `type.verb` came from, as `Class@method`.
     *
     * @return array<string, string>
     */
    public function storyActions(): array
    {
        $this->ensureStoriesCompiled();

        return array_map(fn (array $action) => $action['uses'], $this->storyActions);
    }

    /**
     * The window a verb declared for its activities, on the type → verb
     * ladder: an ISO 8601 duration, `forever` (Verb::FOREVER), or null when
     * no declaration reaches it and `storyfeed.prune.after_days` decides.
     */
    public function retention(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->storyRetention, $type, $verb);
    }

    /**
     * Every declared window, keyed `type.verb` (wildcards allowed).
     *
     * @return array<string, string>
     */
    public function storyRetention(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyRetention;
    }

    /**
     * Which rows a publish of this verb supersedes, on the type → verb
     * ladder (the most specific declaration wins): the roles each kept row
     * is the latest per, and the window as an ISO 8601 duration. Null when
     * no declaration reaches it and every row is kept.
     *
     * @return array{per: list<string>, within: string|null}|null
     */
    public function keepLatest(?string $type, string $verb): ?array
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->storyKeepLatest, $type, $verb);
    }

    /**
     * The types each constrained role of this type and verb may be, as the
     * most specific declaration on the type → verb ladder says; empty when
     * none reaches it.
     *
     * @return array<string, list<string>>
     *
     * @internal
     */
    public function wheres(?string $type, string $verb): array
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->storyWheres, $type, $verb) ?? [];
    }

    /**
     * Every role constraint, keyed `type.verb` (wildcards allowed).
     *
     * @return array<string, array<string, list<string>>>
     */
    public function storyWheres(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyWheres;
    }

    /**
     * Where a queued publish of this type and verb goes, as the most
     * specific declaration on the type → verb ladder says; empty when none
     * reaches it. The call site overrides each part.
     *
     * @return array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}
     *
     * @internal
     */
    public function queueing(?string $type, string $verb): array
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->storyQueue, $type, $verb) ?? [];
    }

    /**
     * Where a message class's queued publishes go, as the line binding it
     * says: the class isn't built until the worker, so its type is the
     * line's.
     *
     * @param  class-string<Story>  $class
     * @return array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}
     *
     * @internal
     */
    public function storyQueueing(string $class): array
    {
        $this->ensureStoriesCompiled();

        foreach ($this->storyActions as $key => $action) {
            if ($action['uses'] === $class) {
                [$type, $verb] = explode('.', $key, 2);

                return $this->queueing($type === '*' ? null : $type, $verb);
            }
        }

        return [];
    }

    /**
     * The story middleware a publish of this type and verb runs, as the
     * pipeline receives it: the `default` group, then what the most specific
     * declaration on the type → verb ladder says, minus what it excludes,
     * with aliases and groups resolved (`batch:5 minutes` is
     * `Storyfeed\Middleware\Batch:5 minutes`) and duplicates dropped.
     *
     * Resolved at each call, as the router resolves a route's middleware at
     * each request, so an alias registered in a service provider applies to
     * a cached manifest too.
     *
     * @return list<string|Closure>
     */
    public function middleware(?string $type, string $verb): array
    {
        $this->ensureStoriesCompiled();

        $declared = $this->resolve($this->storyMiddleware, $type, $verb);

        return app(Registrar::class)->gatherMiddleware($declared['middleware'] ?? [], $declared['excluded'] ?? []);
    }

    /**
     * Every middleware declaration, keyed `type.verb` (wildcards allowed), as declared.
     *
     * @return array<string, array{middleware: list<string|Closure>, excluded: list<string>}>
     */
    public function storyMiddleware(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyMiddleware;
    }

    /**
     * The calendar period a verb's groups live in, on the type → verb
     * ladder (the most specific declaration wins): a day when no
     * declaration reaches it.
     */
    public function period(?string $type, string $verb): Period
    {
        $this->ensureStoriesCompiled();

        $declared = $this->resolve($this->storyPeriods, $type, $verb);

        return $declared === null ? Period::Day : Period::from($declared);
    }

    /**
     * Every declared period, as its value, keyed `type.verb` (wildcards
     * allowed).
     *
     * @return array<string, string>
     */
    public function storyPeriods(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyPeriods;
    }

    /**
     * Every keep-latest declaration, keyed `type.verb` (wildcards allowed).
     *
     * @return array<string, array{per: list<string>, within: string|null}>
     */
    public function storyKeepLatest(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyKeepLatest;
    }

    /**
     * Each verb's fixed actor, a party name, keyed `type.verb`.
     *
     * @return array<string, string>
     */
    public function storyActors(): array
    {
        $this->ensureStoriesCompiled();

        return $this->storyActors;
    }

    /**
     * The configured fallback party, e.g. 'System'. Opt-in: null by default,
     * which leaves unattributable activities anonymous.
     */
    protected function fallbackParty(): ?Party
    {
        $name = config('storyfeed.parties.fallback');

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return $this->party($name);
    }

    /**
     * Resolve or create the party with this name. Overridden by the fake to
     * stub one in memory.
     *
     * With recording off this must not write either: `->actor('Concur')` and
     * `Storyfeed::actor('System', …)` resolve their party at association time,
     * BEFORE publish() gets to decline, and a muted suite that still inserts
     * a feed_parties row per string actor is not muted. An existing row is
     * still found (a read); a missing one comes back as an unsaved model,
     * the way the fake stubs its parties, so the composed no-op Activity
     * carries the role's alias like any other.
     */
    public function party(string $name): Party
    {
        $model = config('storyfeed.models.party', Party::class);

        if ($this->isRecording()) {
            return $model::make($name);
        }

        return $model::find($name) ?? $this->unsavedParty($name);
    }

    protected function unsavedParty(string $name): Party
    {
        $model = config('storyfeed.models.party', Party::class);

        $party = new $model;

        $party->forceFill([
            'key' => Str::slug($name),
            'name' => $name,
            'type' => ObjectType::Service->value,
        ]);

        return $party;
    }

    protected function objectTypeFromModel(string $alias): ObjectType|string|null
    {
        try {
            $class = MorphResolver::classFor($alias);

            if ($class === null || ! is_a($class, HasActivityStreamsType::class, true)) {
                return null;
            }

            return $this->normalizeTerm($class::activityStreamsType(), ObjectType::class);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Coerce a registered term to its enum when recognized; preserve the
     * raw string otherwise. Never drop — extension types must round-trip.
     *
     * @param  class-string  $enum
     */
    protected function normalizeTerm(mixed $type, string $enum): mixed
    {
        if ($type instanceof $enum) {
            return $type;
        }

        if (is_string($type)) {
            return $enum::tryFromLoose($type) ?? $type;
        }

        return $type;
    }

    /**
     * The registry key that resolves for a type + verb, in specificity
     * order. Exposed so coverage tooling can tell a deliberate entry from a
     * `*.*` catch-all.
     */
    public function templateKey(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolveKey($this->grammar, $type, $verb);
    }

    public function iconKey(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolveKey($this->icons, $type, $verb);
    }

    public function glyphIntentKey(?string $type, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

        return $this->resolveKey($this->glyphIntents, $type, $verb);
    }

    public function aggregateTemplateKey(?string $axis, string $verb, ?string $objectType = null): ?string
    {
        // Compiled FIRST: a type's group headline exists only once its Story
        // compiles, so asking before that saw no `axis.type.verb` key at all.
        $this->ensureStoriesCompiled();

        if (($qualified = self::qualifiedKey($axis, $verb, $objectType)) !== "\0"
            && isset($this->aggregateGrammar[$qualified])) {
            return $qualified;
        }

        return $this->resolveKey($this->aggregateGrammar, $axis, $verb);
    }

    /**
     * Candidate registry keys, most specific first.
     *
     * @return array<int, string>
     */
    protected function keysFor(?string $type, string $verb): array
    {
        return $type === null
            ? ["*.{$verb}", '*.*']
            : ["{$type}.{$verb}", "{$type}.*", "*.{$verb}", '*.*'];
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    protected function resolveKey(array $registry, ?string $type, string $verb): ?string
    {
        foreach ($this->keysFor($type, $verb) as $key) {
            if (array_key_exists($key, $registry)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $registry
     * @return TValue|null
     */
    protected function resolve(array $registry, ?string $type, string $verb)
    {
        $key = $this->resolveKey($registry, $type, $verb);

        return $key === null ? null : $registry[$key];
    }
}
