<?php

namespace Storyfeed;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Storyfeed\Actions\CompileStories;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\Collectable;
use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Diagnostics\Doctor;
use Storyfeed\Diagnostics\Report;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Exceptions\UnknownFeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\MorphResolver;
use Throwable;

class StoryfeedManager
{
    protected ?Closure $actorResolver = null;

    /** @var array<string, string|Closure> */
    protected array $grammar = [];

    /** @var array<string, string|Closure> */
    protected array $aggregateGrammar = [];

    /** @var array<string, string> */
    protected array $icons = [];

    /** @var array<string, ActivityType|string> */
    protected array $verbs = self::DEFAULT_VERBS;

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
     * Morph aliases whose models are collection-natured (activities about
     * them arrive in collections) — the registry override for the
     * Collectable marker interface. Registry wins.
     *
     * @var array<string, true>
     */
    protected array $collectables = [];

    /**
     * Registered story definitions, in registration order.
     *
     * Typed loosely on purpose: this holds whatever the app passed, and
     * validating it is exactly what storyDefinitions() is for. Narrowing it
     * here would tell the analyser the checks are unreachable while leaving
     * the runtime just as exposed.
     *
     * @var array<int|string, mixed>
     */
    protected array $stories = [];

    protected bool $storiesCompiled = false;

    /**
     * The compiled output, cached in memory (or seeded from a manifest).
     *
     * @var array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}|null
     */
    protected ?array $compiled = null;

    /**
     * What the last compile merged into the registries, so a recompile can
     * withdraw it first (see retractApplied()).
     *
     * @var array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}|null
     */
    protected ?array $applied = null;

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

    /**
     * Compose and publish an activity in one call.
     */
    public function record(
        string|FeedVerb|BackedEnum $verb,
        Model|string|null $object = null,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        Model|string|null $context = null,
        array $data = [],
        DateTimeInterface|string|null $publishedAt = null,
        bool $replace = false,
        iterable $objects = [],
    ): Activity {
        return $this->activity($verb, $object)
            ->when($objects !== [], fn (PendingActivity $a) => $a->objects($objects))
            ->actor($actor)
            ->target($target)
            ->context($context)
            ->when($data !== [], fn (PendingActivity $a) => $a->data($data))
            ->when($publishedAt !== null, fn (PendingActivity $a) => $a->publishedAt($publishedAt))
            ->replace($replace)
            ->publish();
    }

    /**
     * Attribute activities to an actor.
     *
     * With a callback, everything published inside it is attributed to that
     * actor — the scoped counterpart to `parties.fallback`, and what you
     * want inside a job or console command:
     *
     *   Storyfeed::as('System', function () {
     *       Storyfeed::record('sync', object: $invoice);
     *   });
     *
     * Without one, it seeds a builder: Storyfeed::as('System')->verb('sync').
     *
     * An explicit ->actor() still wins inside the scope, and the previous
     * resolver is always restored — including when the callback throws.
     *
     * @return ($callback is null ? PendingActivity : mixed)
     */
    public function as(Model|string $actor, ?callable $callback = null): mixed
    {
        $resolved = is_string($actor) ? $this->party($actor) : $actor;

        if ($callback === null) {
            return $this->activity()->actor($resolved);
        }

        $previous = $this->actorResolver;

        $this->actorResolver = fn () => $resolved;

        try {
            return $callback();
        } finally {
            $this->actorResolver = $previous;
        }
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
     * icons(), stories() and checks(), one level up: those describe how an
     * activity READS, this describes which activities a surface is about.
     *
     * Both forms normalize into one FeedDefinition, exactly as a Story class
     * and an ad-hoc StoryDefinition do — so the closure stays first-class (a
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

        return $this;
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
     * :actor/:object/:target/:context placeholders, or closures receiving
     * the Activity and returning a pre-rendered headline.
     *
     * @param  array<string, string|Closure>  $grammar
     */
    public function grammar(array $grammar, bool $merge = true): static
    {
        $this->grammar = $merge ? [...$this->grammar, ...$grammar] : $grammar;

        return $this;
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
        return $publisher->toFeedStory()?->publish();
    }

    /**
     * Register story definitions — classes, StoryDefinition objects, or
     * `'type.verb' => [...]` arrays (see Storyfeed\Story).
     *
     * @param  array<int|string, class-string<Story>|StoryDefinition|array<string, mixed>>  $stories
     */
    public function stories(array $stories, bool $merge = true): static
    {
        $this->stories = $merge ? [...$this->stories, ...$stories] : $stories;

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
     * and the verb registry, and an app that calls stories() before axes()
     * would otherwise get a confusing "unknown axis" throw for a correct
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

        if ($this->stories === []) {
            return;
        }

        $compiled = $this->compiled ?? (new CompileStories)($this->storyDefinitions(), $this);

        $this->compiled = $compiled;

        $this->grammar = [...$compiled['grammar'], ...$this->grammar];
        $this->aggregateGrammar = [...$compiled['aggregateGrammar'], ...$this->aggregateGrammar];
        $this->icons = [...$compiled['icons'], ...$this->icons];
        $this->verbs = [...$compiled['verbs'], ...$this->verbs];

        foreach (array_keys($compiled['verbs']) as $verb) {
            $this->declaredVerbs[$verb] ??= true;
        }

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

        foreach (['grammar', 'aggregateGrammar', 'icons', 'verbs'] as $registry) {
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
     * @return array<int, StoryDefinition>
     */
    public function storyDefinitions(): array
    {
        $definitions = [];

        foreach ($this->stories as $key => $story) {
            $definitions[] = match (true) {
                $story instanceof StoryDefinition => $story,
                is_array($story) => StoryDefinition::fromArray((string) $key, $story),
                is_string($story) && is_a($story, Story::class, true) => StoryDefinition::fromStory($story),
                default => throw StoryMisconfigured::notAStory(is_string($story) ? $story : get_debug_type($story)),
            };
        }

        return $definitions;
    }

    /**
     * The compiled arrays — closure-free by construction, so a manifest can
     * var_export them.
     *
     * @return array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}
     */
    public function compiledStories(): array
    {
        return (new CompileStories)($this->storyDefinitions(), $this);
    }

    /**
     * Seed the compiled arrays from a cached manifest, skipping compilation.
     *
     * @param  array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}  $compiled
     */
    public function useCompiledStories(array $compiled): static
    {
        $this->compiled = $compiled;
        $this->storiesCompiled = false;

        return $this;
    }

    /** @return array<int|string, mixed> */
    public function registeredStories(): array
    {
        return $this->stories;
    }

    /**
     * Compile on first read if boot has not done it yet. Console commands,
     * tests and the fake all reach the registries outside a normal request
     * lifecycle; this mirrors the existing `$this->axes ??= defaultAxes()`
     * laziness and costs one boolean per resolution.
     */
    protected function ensureStoriesCompiled(): void
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
     * Designate morph aliases as collection-natured (see Contracts\Collectable).
     *
     * @param  array<int, string>  $aliases
     */
    public function collectables(array $aliases, bool $merge = true): static
    {
        $designated = array_fill_keys($aliases, true);

        $this->collectables = $merge ? [...$this->collectables, ...$designated] : $designated;

        return $this;
    }

    /**
     * Registry wins; the Collectable marker interface is the model-side
     * declaration for first-party models.
     */
    public function isCollectable(?string $alias): bool
    {
        if ($alias === null) {
            return false;
        }

        if (isset($this->collectables[$alias])) {
            return true;
        }

        $class = MorphResolver::classFor($alias);

        return $class !== null && is_a($class, Collectable::class, true);
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
     * @param  array<string, string|Closure>  $grammar
     */
    public function aggregateGrammar(array $grammar, bool $merge = true): static
    {
        $this->aggregateGrammar = $merge ? [...$this->aggregateGrammar, ...$grammar] : $grammar;

        return $this;
    }

    /**
     * Register icons, keyed like grammar ("type.verb", wildcards allowed).
     *
     * @param  array<string, string>  $icons
     */
    public function icons(array $icons, bool $merge = true): static
    {
        $this->icons = $merge ? [...$this->icons, ...$icons] : $icons;

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
     * Typed on the KEY loosely on purpose, for the same reason `$stories` is:
     * this receives whatever the app passed, and validating it is what the loop
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
     * @param  array<string, ObjectType|string>  $objectTypes
     */
    public function objectTypes(array $objectTypes, bool $merge = true): static
    {
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
        if (! is_a($enum, FeedVerb::class, true) || ! is_a($enum, BackedEnum::class, true)) {
            return [];
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

        return $this->resolve($this->grammar, $type, $verb);
    }

    /**
     * Resolve the aggregate grammar entry for a group's axis + verb.
     * Resolution order: axis.verb → axis.* → *.verb → *.*
     */
    public function aggregateTemplate(?string $axis, string $verb): string|Closure|null
    {
        $this->ensureStoriesCompiled();

        return $this->resolve($this->aggregateGrammar, $axis, $verb);
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

    /** @return array<string, string|Closure> */
    public function registeredGrammar(): array
    {
        $this->ensureStoriesCompiled();

        return $this->grammar;
    }

    /** @return array<string, string|Closure> */
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

    /** @return array<string, ActivityType|string> */
    public function registeredVerbs(): array
    {
        $this->ensureStoriesCompiled();

        return $this->verbs;
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
     */
    public function party(string $name): Party
    {
        $model = config('storyfeed.models.party', Party::class);

        return $model::make($name);
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

    public function aggregateTemplateKey(?string $axis, string $verb): ?string
    {
        $this->ensureStoriesCompiled();

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
