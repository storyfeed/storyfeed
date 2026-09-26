<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use Error;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ReflectionClass;
use Storyfeed\ActivityContext;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Grouping\Period;
use Storyfeed\Middleware\Batch;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\ManifestClosure;

/**
 * A story as data — the normalized form every authoring path funnels into.
 *
 * Every authoring path in routes/feed.php funnels into it, one compiler:
 * a line (`Story::for(Comment::class)->verb('comment')->headline(…)`), an
 * action on a resource class, and a message class bound to its verb.
 * Because CompileStories consumes only this type, they compile alike.
 *
 * The `Story` facade is the front door onto this class: `Story::verb('place')`
 * returns a Verb that is already registered, the way `Route::get()`
 * returns a Route already in the collection. That is why it is a MUTABLE
 * builder: a definition is registered when it is made and configured after.
 *
 * Every definition knows where it was written (`routes/feed.php:14`), so two
 * definitions of one key fail naming both lines.
 *
 * IT IS ALSO WHAT AN ACTION RECEIVES, in a resource Story class
 * (`public function place(Verb $verb): Verb`; see ResourceClass). Every
 * method here belongs to one phase, by method and never by value: `actor()`
 * is read at each publish; everything else is read when stories compile,
 * which is all the feed, `storyfeed:list`, the doctor and `storyfeed:cache`
 * ever see.
 */
final class Verb
{
    use Conditionable, CreatesRoleConstraints;

    /** What `keepForever()` compiles to. */
    public const FOREVER = 'forever';

    /** @var array<int, Group> */
    protected array $groups = [];

    protected string|Closure|FeedHeadline|null $headline = null;

    protected string|Closure|FeedHeadline|null $anonymousHeadline = null;

    protected string|FeedNoun|null $noun = null;

    protected ObjectType|string|null $activityStreamsType = null;

    protected ?string $icon = null;

    protected ?string $intent = null;

    protected ActivityType|string|null $type = null;

    /** @var list<string>|null null: the default set (the object) */
    protected ?array $missing = null;

    protected string|Closure|FeedHeadline|null $missingHeadline = null;

    /** Null: not said, so a wildcard's answer stands. */
    protected ?bool $forgetWhenMissing = null;

    /** An ISO 8601 duration, `forever`, or null: not said, so `prune.after_days` stands. */
    protected ?string $retention = null;

    /** @var array{per: list<string>, within: string|null}|null null: every row is kept */
    protected ?array $keepLatest = null;

    /** The calendar bucket its groups live in; null: not said, so a broader definition's, or a day. */
    protected ?Period $period = null;

    /** @var array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool} where a queued publish goes, as declared */
    protected array $queueing = [];

    /** @var list<string|Closure> story middleware, in the order declared */
    protected array $middleware = [];

    /** @var list<string> middleware taken out of what this verb would otherwise run */
    protected array $excludedMiddleware = [];

    /** Per publish: who acted, when the call site and `Storyfeed::actor()` didn't say. */
    protected Model|string|null $actor = null;

    /** `App\Stories\OrderStory@place`, or a message class: the action this definition came from. */
    protected ?string $action = null;

    /** Whether that action takes the request, and so runs again at each publish. */
    protected bool $takesRequest = false;

    /** Made by `Story::for(…)`: its group headlines are keyed per type. */
    protected bool $typeScoped = false;

    /** What `->name()` was given, or null: unnamed, as a route is until named. */
    protected ?string $name = null;

    /** @var array<string, string> a resource's name for each of its types, `alias => name` */
    protected array $typeNames = [];

    /** The prefix of every open `Story::name('billing.')->group()`, outermost first. */
    protected string $namePrefix = '';

    /** @var array<string, list<string>> the morph aliases each constrained role may be, `role => aliases` */
    protected array $wheres = [];

    /**
     * @param  array<int, string>  $objectTypes  morph aliases; ['*'] for object-less
     */
    protected function __construct(
        public readonly array $objectTypes,
        public readonly string $verb,
        public readonly string $source,
    ) {}

    /**
     * From a registry key — `'document.upload'`, or `'*.upload'` for an
     * object-less activity. Deliberately the same `{type}.{verb}` string the
     * whole package already speaks, so wildcards need no new vocabulary.
     *
     * The source defaults to the line that called this, so two definitions of
     * one key are a conflict naming both lines rather than a silent overwrite.
     */
    public static function make(string $key, ?string $source = null): self
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw StoryMisconfigured::invalidDefinitionKey($key);
        }

        return new self([$parts[0]], $parts[1], $source ?? self::caller());
    }

    /**
     * From an object type and a verb, resolving model classes to morph aliases.
     *
     * @param  string|array<int, string>  $objectType  model class, morph alias, '*', or a list
     */
    public static function for(string|array $objectType, string|FeedVerb|BackedEnum $verb, ?string $source = null): self
    {
        $aliases = array_map(self::alias(...), (array) $objectType);
        $resolved = self::normalizeVerb($verb);

        $definition = new self(array_values($aliases), $resolved, $source ?? self::caller());

        // A FeedVerb case carries its own AS2.0 mapping, and that is the whole
        // point of the two layers composing: an enum case is a VERB, a Story is
        // a specific activity. Losing the mapping here would silently downgrade
        // every story-authored verb to the base `Activity` type.
        if ($verb instanceof FeedVerb && $verb->activityType() !== null) {
            $definition->type = $verb->activityType();
        }

        return $definition;
    }

    /**
     * From a message class bound in routes/feed.php: the line gives the
     * types (null outside a scope, where the class's own stand), the verb
     * and the source.
     *
     * @param  class-string<Story>|Story  $story
     * @param  array<int, string>|null  $objectTypes
     */
    public static function fromStory(
        string|Story $story,
        ?array $objectTypes,
        string|FeedVerb|BackedEnum $verb,
        string $source,
    ): self {
        $instance = is_string($story) ? self::presentation($story) : $story;
        $class = $instance::class;
        $objectTypes ??= $instance->objectType;

        if ($objectTypes === null) {
            throw StoryMisconfigured::missingObjectType($class);
        }

        try {
            // The class is the action, as an invokable controller is a
            // route's: it is what storyfeed:list shows, what the class's
            // $this->activity() finds its verb by, and what makes a second
            // definition of the verb a conflict.
            $definition = self::for($objectTypes, $verb, $source)
                ->fromAction($class, false)
                ->headline($instance->headline())
                ->groups(...$instance->groups());

            if ($instance->icon() !== null) {
                $definition = $definition->icon($instance->icon());
            }

            if ($instance->intent() !== null) {
                $definition = $definition->intent($instance->intent());
            }

            if ($instance->type !== null) {
                $definition = $definition->type($instance->type);
            }

            if (($missing = $instance->missing()) !== null) {
                $definition = $definition->missing(...$missing);
            }

            if ($instance->keepForever()) {
                $definition = $definition->keepForever();
            } elseif (($window = $instance->keepFor()) !== null) {
                $definition = $definition->keepFor($window);
            }

            if (($latest = $instance->keepLatest()) !== null) {
                $definition = $definition->keepLatest(...($latest === true ? [] : $latest));
            }

            if (($period = $instance->period()) !== null) {
                $definition = $definition->groupedPer($period);
            }

            // A job's `middleware()`, read when stories compile like the
            // rest, so it can't depend on what the class was constructed with.
            if (($middleware = $instance->middleware()) !== []) {
                $definition->middleware($middleware);
            }
        } catch (Error $e) {
            // "must not be accessed before initialization": a presentation
            // method read what only the constructor sets.
            if (! str_contains($e->getMessage(), 'before initialization')) {
                throw $e;
            }

            throw StoryMisconfigured::presentationReadsState($class, $e);
        }

        return $definition;
    }

    /**
     * The instance a message class's presentation is read from, made WITHOUT
     * its constructor.
     *
     * A message class is constructed with its data (`new OrderConfirmed(
     * $order)`), and at boot there is no order to pass. Presentation is the
     * same for every activity of the type, so it never needs one: the
     * property defaults (`$verb`, `$objectType`, `$type`) are set without the
     * constructor, and anything the constructor sets is not, so a headline
     * that reads it fails here, at compile, naming the class. Static
     * presentation methods would enforce that rule by themselves, but on a
     * class Laravel developers read as a Notification, whose `toMail()` and
     * `via()` are instance methods, they would be the odd ones out.
     *
     * @param  class-string<Story>  $class
     */
    public static function presentation(string $class): Story
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * The keys the array form accepts.
     *
     * @deprecated Declare it in routes/feed.php; removed before v1.
     */
    public const ARRAY_KEYS = ['name', 'headline', 'anonymousHeadline', 'icon', 'intent', 'type', 'noun', 'activityStreamsType', 'missing', 'missingHeadline', 'forgetWhenMissing', 'keepFor', 'keepForever', 'keepLatest', 'groupedPer', 'middleware', 'withoutMiddleware', 'actor', 'where', 'groups'];

    /**
     * Configure from the array form: what an action returning an array
     * says. Return the fluent Verb from the action instead.
     *
     * @param  array<string, mixed>  $spec
     *
     * @internal
     *
     * @deprecated Declare it in routes/feed.php; removed before v1.
     */
    public function fill(array $spec, string $name): self
    {
        foreach (array_keys($spec) as $given) {
            if (! in_array($given, self::ARRAY_KEYS, true)) {
                throw StoryMisconfigured::unknownDefinitionKey($name, (string) $given, self::ARRAY_KEYS);
            }
        }

        $definition = $this;

        if (isset($spec['name'])) {
            $definition = $definition->name((string) $spec['name']);
        }

        if (isset($spec['headline'])) {
            /** @var string|Closure|FeedHeadline $headline */
            $headline = $spec['headline'];
            $definition = $definition->headline($headline);
        }

        if (isset($spec['anonymousHeadline'])) {
            /** @var string|Closure|FeedHeadline $anonymous */
            $anonymous = $spec['anonymousHeadline'];
            $definition = $definition->anonymousHeadline($anonymous);
        }

        if (isset($spec['noun'])) {
            /** @var string|FeedNoun $noun */
            $noun = $spec['noun'];
            $definition = $definition->noun($noun);
        }

        if (isset($spec['activityStreamsType'])) {
            /** @var ObjectType|string $objectType */
            $objectType = $spec['activityStreamsType'];
            $definition = $definition->activityStreamsType($objectType);
        }

        if (isset($spec['icon'])) {
            $definition = $definition->icon((string) $spec['icon']);
        }

        if (isset($spec['intent'])) {
            $definition = $definition->intent((string) $spec['intent']);
        }

        if (isset($spec['type'])) {
            $definition = $definition->type($spec['type']);
        }

        // array_key_exists, not isset: `'missing' => []` means "no role".
        if (array_key_exists('missing', $spec)) {
            /** @var string|list<string> $missing */
            $missing = $spec['missing'];
            $definition = $definition->missing(...(array) $missing);
        }

        if (isset($spec['missingHeadline'])) {
            /** @var string|Closure|FeedHeadline $missingHeadline */
            $missingHeadline = $spec['missingHeadline'];
            $definition = $definition->missingHeadline($missingHeadline);
        }

        if (isset($spec['forgetWhenMissing'])) {
            $definition = $definition->forgetWhenMissing((bool) $spec['forgetWhenMissing']);
        }

        if (isset($spec['keepFor'])) {
            /** @var string|DateInterval $window */
            $window = $spec['keepFor'];
            $definition = $definition->keepFor($window);
        }

        if (! empty($spec['keepForever'])) {
            $definition = $definition->keepForever();
        }

        // `true` for the plain form, or its named arguments:
        // `'keepLatest' => ['per' => ['object', 'actor']]`.
        if (! empty($spec['keepLatest'])) {
            /** @var true|array{per?: list<string>|string|null, within?: string|DateInterval|null} $latest */
            $latest = $spec['keepLatest'];
            $definition = $definition->keepLatest(...($latest === true ? [] : $latest));
        }

        // A Period, or its value: `'groupedPer' => 'week'`.
        if (isset($spec['groupedPer'])) {
            /** @var Period|string $period */
            $period = $spec['groupedPer'];
            $definition = $definition->groupedPer($period);
        }

        if (! empty($spec['middleware'])) {
            /** @var string|list<string|Closure>|Closure $middleware */
            $middleware = $spec['middleware'];
            $definition->middleware($middleware);
        }

        if (! empty($spec['withoutMiddleware'])) {
            /** @var string|list<string> $without */
            $without = $spec['withoutMiddleware'];
            $definition->withoutMiddleware($without);
        }

        if (isset($spec['actor'])) {
            /** @var Model|string $actor */
            $actor = $spec['actor'];
            $definition = $definition->actor($actor);
        }

        // The route action's `where` key: `'where' => ['actor' => User::class]`.
        if (isset($spec['where'])) {
            if (! is_array($spec['where'])) {
                throw new InvalidArgumentException("The array form's 'where' key in [{$name}] takes role => types, like ['actor' => User::class].");
            }

            foreach ($spec['where'] as $role => $types) {
                /** @var string|list<string> $types */
                $definition->whereRole((string) $role, $types);
            }
        }

        /** @var array<int, Group> $groups */
        $groups = $spec['groups'] ?? [];

        return $definition->groups(...$groups);
    }

    /**
     * The headline: a template (`':actor placed :object[ with :target]'`), a
     * translation key read in the reader's locale (`FeedHeadline::trans()`),
     * or a closure that receives the Activity when the feed is read.
     *
     * A closure may return either. A result naming a role token is a
     * template, so names stay tokens and links; one without is finished text.
     *
     * @param  string|FeedHeadline|Closure(ActivityContext): (string|FeedHeadline)  $headline
     */
    public function headline(string|Closure|FeedHeadline $headline): self
    {
        $this->headline = $headline;

        return $this;
    }

    /**
     * The headline for an activity with no actor (`Storyfeed::anonymous()`,
     * a null actor). Resolved on the same type → verb ladder as headline(),
     * and tried first for actorless rows. It cannot name `:actor`.
     *
     * Often an optional segment in headline() does the same job with one
     * sentence: `'[:actor ]confirmed :object'`.
     *
     * @param  string|FeedHeadline|Closure(ActivityContext): (string|FeedHeadline)  $headline
     */
    public function anonymousHeadline(string|Closure|FeedHeadline $headline): self
    {
        if (is_string($headline) && str_contains($headline, ':actor')) {
            throw new InvalidArgumentException(
                "The anonymous headline for [{$this->key()}] must not contain :actor or :actors — it is the sentence for an activity without one.",
            );
        }

        $this->anonymousHeadline = $headline;

        return $this;
    }

    /**
     * The plural forms of the thing this type holds, `'dish|dishes'`, used
     * where a group can't name one entity. On a fallback (`Story::for(X)->
     * fallback()`) it is the type's noun; on a verb, the noun for that verb
     * only ("uploads of a document are files"). Both forms are required.
     */
    public function noun(string|FeedNoun $noun): self
    {
        if (is_string($noun)) {
            FeedNoun::of($noun);
        }

        $this->noun = $noun;

        return $this;
    }

    /**
     * The AS2.0 object type of this definition's object types — the registry
     * form of HasActivityStreamsType. A per-TYPE fact, so any definition in a
     * type scope may set it, and two that disagree are a conflict.
     */
    public function activityStreamsType(ObjectType|string $type): self
    {
        $this->activityStreamsType = $type;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * The glyph's intent — a free-form, app-owned word the renderer maps to
     * its palette. Compiles to the glyph-intent registry, alongside the token.
     */
    public function intent(string $intent): self
    {
        $this->intent = $intent;

        return $this;
    }

    public function type(ActivityType|string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * The roles this activity is about: once one of them is a tombstone (its
     * model was deleted), the activity is redundant as news, though still
     * true as history, and a renderer can tell it as a whole ("an order Dana
     * placed was later removed").
     *
     *     Story::verb('turn_into')->missing('object', 'result');
     *     Story::verb('ask_about')->missing();          // no role: the question stands
     *
     * Without this call the object is the one such role, and a removal verb
     * (its AS2 type is Delete, Remove, Undo or Reject) has none, because the
     * object it names being gone is expected. This call REPLACES that set
     * rather than adding to it, and `->missing()` with no roles means none.
     */
    public function missing(string ...$roles): self
    {
        foreach ($roles as $role) {
            if (! in_array($role, ActivityRoles::STORED, true)) {
                throw new InvalidArgumentException(
                    "->missing() on [{$this->key()}] names [{$role}], which is not a role. The roles are "
                    .implode(', ', ActivityRoles::STORED).'.',
                );
            }
        }

        $this->missing = array_values(array_unique($roles));

        return $this;
    }

    /**
     * What this activity reads as once it is redundant: a role it is about
     * (see `->missing()`) was deleted. App-authored grammar, like any
     * headline, sent beside the normal one as `missing_headline_template`;
     * `headline_template` never changes, and a renderer may show either.
     *
     *     Story::verb('place')->missingHeadline(':actor placed an order that is no longer available');
     *
     * @param  string|FeedHeadline|Closure(ActivityContext): (string|FeedHeadline)  $headline
     */
    public function missingHeadline(string|Closure|FeedHeadline $headline): self
    {
        $this->missingHeadline = $headline;

        return $this;
    }

    /**
     * Delete this verb's activities once they are redundant, instead of
     * telling them about a tombstone: when a role the verb is about (see
     * `->missing()`) is PERMANENTLY deleted. Decided when the tombstone is
     * made — by the model's delete event, `Storyfeed::tombstone()` after a
     * bulk delete, or the trickle — never when the feed is read.
     *
     * Never on a soft delete, so a restore can always undo one. A soft-
     * deleted model that is later force-deleted forgets them then.
     */
    public function forgetWhenMissing(bool $forget = true): self
    {
        $this->forgetWhenMissing = $forget;

        return $this;
    }

    /**
     * How long this verb's activities are worth keeping: `storyfeed:prune`
     * permanently deletes the ones published longer ago than this.
     *
     *     Story::verb('view')->keepFor('30 days');
     *
     * Overrides `storyfeed.prune.after_days` for this verb, in either
     * direction, and works with it unset. A string Carbon reads as an
     * interval (`'30 days'`, `'6 months'`, `'P2W'`) or a DateInterval.
     */
    public function keepFor(string|DateInterval $window): self
    {
        $this->retention = self::window($window, $this->key());

        return $this;
    }

    /**
     * Never prune this verb's activities, whatever `storyfeed.prune.after_days`
     * says: what makes a global window safe to switch on for a feed where
     * some verbs are the record.
     */
    public function keepForever(): self
    {
        $this->retention = self::FOREVER;

        return $this;
    }

    /**
     * Keep only the latest of this verb's activities about one thing: each
     * publish supersedes the earlier rows on its key.
     *
     *     Story::for(Task::class)->verb('reschedule')->keepLatest();
     *     Story::verb('save')->keepLatest(per: ['object', 'actor']);
     *     Story::for(Task::class)->verb('update')->keepLatest(within: '10 minutes');
     *
     * The key is the object, or the roles `per` names, plus the verb. The
     * latest `published_at` wins, whatever order the rows arrive in: a
     * backdated publish older than a live row on its key is stored already
     * superseded, so a backfill can replay in any order. `within` limits
     * the match to rows published within that long of the new one, which
     * coalesces a burst and keeps the rest of the day.
     *
     * Superseded rows are soft-deleted (`storyfeed.keep_latest.delete`), so
     * they leave every feed, `log()` included, and stay in the table. A
     * timeline that needs every row should not declare this.
     *
     * Declared here only, never at the call site: `ShouldBeUnique` lives on
     * the job, not the dispatch. The two answer different questions:
     * `ShouldBeUnique` keeps the first pending job, `keepLatest()` keeps the
     * latest stored row.
     *
     * @param  list<string>|string|null  $per  role names; null is the object
     */
    public function keepLatest(array|string|null $per = null, string|DateInterval|null $within = null): self
    {
        $roles = $per === null ? ['object'] : array_values(array_unique((array) $per));

        foreach ($roles as $role) {
            if (! in_array($role, ActivityRoles::STORED, true)) {
                throw new InvalidArgumentException(
                    "->keepLatest() on [{$this->key()}] was given per: '{$role}', which is not a role. "
                    .'Name roles: '.implode(', ', ActivityRoles::STORED).'.',
                );
            }
        }

        if ($roles === []) {
            throw new InvalidArgumentException(
                "->keepLatest() on [{$this->key()}] was given no roles to keep the latest per. Leave per: out to key on the object.",
            );
        }

        $this->keepLatest = [
            // In the stored roles' order, so `['actor', 'object']` and
            // `['object', 'actor']` compile the same.
            'per' => array_values(array_intersect(ActivityRoles::STORED, $roles)),
            'within' => $within === null ? null : self::window($within, $this->key(), 'keepLatest', 'within: '),
        ];

        return $this;
    }

    /**
     * Where this verb's queued publishes go, as a job class's `$connection`
     * and `$queue` say where it goes: a default, and the call site's
     * `->onConnection()` / `->onQueue()` overrides it.
     *
     *     Story::for(Order::class)->verb('confirm')->onQueue('feed');
     *
     *     Storyfeed::activity('confirm', $order)->queue();                     // on `feed`
     *     Storyfeed::activity('confirm', $order)->onQueue('urgent')->queue();  // on `urgent`
     *
     * Declaring it doesn't queue anything: a publish is queued by
     * `->queue()`, or by a message class that implements `ShouldQueue`.
     */
    public function onConnection(string|BackedEnum $connection): self
    {
        $this->queueing['connection'] = $connection instanceof BackedEnum ? (string) $connection->value : $connection;

        return $this;
    }

    /** The queue this verb's queued publishes go on — see onConnection(). */
    public function onQueue(string|BackedEnum $queue): self
    {
        $this->queueing['queue'] = $queue instanceof BackedEnum ? (string) $queue->value : $queue;

        return $this;
    }

    /**
     * How long a queued publish waits before the worker takes it, in
     * seconds or as an interval: `->delay('5 seconds')`. The activity's
     * `published_at` is still the moment `->queue()` was called.
     */
    public function delay(int|string|DateInterval $delay): self
    {
        if (is_int($delay) && $delay < 0) {
            throw new InvalidArgumentException("->delay() on [{$this->key()}] was given {$delay}, which is not a number of seconds.");
        }

        $this->queueing['delay'] = is_int($delay)
            ? $delay
            : (int) CarbonInterval::make(self::window($delay, $this->key(), 'delay'))?->totalSeconds;

        return $this;
    }

    /**
     * Publish once the surrounding database transaction has committed, as a
     * job's `afterCommit()` waits for it: queued or not. Off by default, when
     * a queued publish follows its connection's `after_commit`.
     */
    public function afterCommit(): self
    {
        $this->queueing['afterCommit'] = true;

        return $this;
    }

    /** Publish without waiting for the transaction, whatever the connection's `after_commit` says. */
    public function beforeCommit(): self
    {
        $this->queueing['afterCommit'] = false;

        return $this;
    }

    /**
     * Drop a queued publish silently when a model it names was deleted before
     * the worker took it, as a job's `$deleteWhenMissingModels` does. Without
     * it, the job fails and lands in `failed_jobs`.
     */
    public function deleteWhenMissingModels(bool $delete = true): self
    {
        $this->queueing['deleteWhenMissingModels'] = $delete;

        return $this;
    }

    /**
     * Middleware this verb's activities go through when they are published,
     * after the `default` group, as a route's own middleware runs after its
     * group's. A class, an alias with arguments (`'batch:5 minutes'`), a
     * group's name, or a closure:
     *
     *     Story::verb('pay')->middleware('audit');
     *     Story::verb('pay')->middleware(['audit', fn (PendingActivity $activity, Closure $next) => $next($activity)]);
     *
     * Each receives the PendingActivity and `$next`; what it does after
     * `$next($activity)` sees the stored Activity. Appends. With no argument,
     * returns what was declared, as `Route::middleware()` does.
     *
     * @param  string|list<string|Closure>|Closure|null  $middleware
     * @return ($middleware is null ? list<string|Closure> : self)
     */
    public function middleware(string|array|Closure|null $middleware = null): self|array
    {
        if ($middleware === null) {
            return $this->middleware;
        }

        $this->middleware = [...$this->middleware, ...self::middlewareList($middleware, $this->key())];

        return $this;
    }

    /**
     * Middleware ahead of what was declared: a bound line's, before its
     * class's own.
     *
     * @param  list<string|Closure>  $middleware
     *
     * @internal
     */
    public function prependMiddleware(array $middleware): self
    {
        $this->middleware = [...$middleware, ...$this->middleware];

        return $this;
    }

    /**
     * Take middleware out of what this verb would run, the default group's
     * included, as `Route::withoutMiddleware()` does. A name matches the
     * same name only: excluding `batch` leaves `batch:5 minutes` in place.
     *
     * @param  string|list<string>  $middleware
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        foreach ((array) $middleware as $name) {
            if (trim($name) === '') {
                throw new InvalidArgumentException("->withoutMiddleware() on [{$this->key()}] takes middleware names.");
            }

            if (! in_array($name, $this->excludedMiddleware, true)) {
                $this->excludedMiddleware[] = $name;
            }
        }

        return $this;
    }

    /**
     * What `withoutMiddleware()` took out.
     *
     * @return list<string>
     */
    public function excludedMiddleware(): array
    {
        return $this->excludedMiddleware;
    }

    /**
     * Join the actor's sitting, with this verb's own quiet window:
     *
     *     Story::verb('comment')->batched();                      // storyfeed.grouping.batch.quiet_minutes
     *     Story::for(Todo::class)->verb('add')->batched(within: '5 minutes');
     *
     * Shorthand for the `batch` middleware, as `->can('update', 'post')` is
     * for `can:update,post`: with a window it takes `batch` out and puts
     * `batch:5 minutes` in; without one it makes sure `batch` is there.
     * The last of `batched()` and `unbatched()` wins.
     */
    public function batched(string|DateInterval|null $within = null): self
    {
        $this->forgetBatch();

        if ($within === null) {
            $this->middleware[] = 'batch';

            return $this;
        }

        $window = self::window($within, $this->key(), 'batched', 'within: ');

        $this->excludedMiddleware[] = 'batch';
        $this->middleware[] = 'batch:'.(is_string($within) && ! str_contains($within, ',') ? trim($within) : $window);

        return $this;
    }

    /**
     * Never join a sitting: `->withoutMiddleware('batch')`, and any `batch`
     * this verb was given is taken back. An unbatched activity doesn't join,
     * extend or close the actor's open sitting.
     *
     *     Story::for(Project::class)->verb('create')->unbatched();
     */
    public function unbatched(): self
    {
        $this->forgetBatch();

        $this->excludedMiddleware[] = 'batch';

        return $this;
    }

    private function forgetBatch(): void
    {
        $this->middleware = array_values(array_filter(
            $this->middleware,
            fn (string|Closure $middleware) => ! is_string($middleware)
                || ! in_array(explode(':', $middleware, 2)[0], ['batch', Batch::class], true),
        ));

        $this->excludedMiddleware = array_values(array_diff($this->excludedMiddleware, ['batch']));
    }

    /**
     * What this verb declared about middleware, or null when it said
     * nothing and a broader definition's answer stands.
     *
     * @return array{middleware: list<string|Closure>, excluded: list<string>}|null
     *
     * @internal
     */
    public function declaredMiddleware(): ?array
    {
        if ($this->middleware === [] && $this->excludedMiddleware === []) {
            return null;
        }

        return ['middleware' => $this->middleware, 'excluded' => $this->excludedMiddleware];
    }

    /**
     * Middleware as given to `->middleware()`, `Story::middleware()` or a
     * Story class's `middleware()`, as a list. Strings and closures only:
     * those are what `storyfeed:list` can show and `storyfeed:cache` can
     * write, as route middleware is.
     *
     * @param  string|array<mixed>|Closure  $middleware
     * @return list<string|Closure>
     *
     * @internal
     */
    public static function middlewareList(string|array|Closure $middleware, string $where = 'Story::middleware()'): array
    {
        $list = [];

        foreach (is_array($middleware) ? $middleware : [$middleware] as $entry) {
            if ($entry instanceof Closure || (is_string($entry) && trim($entry) !== '')) {
                $list[] = $entry;

                continue;
            }

            throw new InvalidArgumentException(
                "The middleware for [{$where}] must be class names, aliases (`'batch:5 minutes'`) or closures, got "
                .get_debug_type($entry).'. An object can\'t be listed or cached.',
            );
        }

        return $list;
    }

    /**
     * Who acted, when the call site didn't say and no `Storyfeed::actor()` scope
     * is open: a party name (`'Stripe'`), or, from an action that takes the
     * request, a model. Ranks below both and above the default actor (the
     * signed-in user, then `parties.fallback`). Null or `''` says nothing.
     *
     *     public function refund(Verb $verb, Request $request): Verb
     *     {
     *         return $verb->headline(':actor refunded :object')->actor($request->string('provider')->value());
     *     }
     *
     * A name must be declared with `Storyfeed::parties()` once any are.
     */
    public function actor(Model|string|null $actor): self
    {
        $this->actor = is_string($actor) && trim($actor) === '' ? null : $actor;

        return $this;
    }

    /**
     * The types a role may be, as `->where()` constrains a route parameter:
     * a publish that fills the role with anything else throws
     * StoryRoleMismatch, naming the verb, the role, what was expected and
     * what was given, the way a route whose constraint fails doesn't match.
     *
     *     Story::for(Order::class)->verb('refund')->whereActor(User::class);
     *     Story::verb('import')->whereRole('origin', Warehouse::class, Supplier::class);
     *     Story::verb('sync')->whereActor('party');      // a Party, which lives only in the feed
     *
     * A model class compares by its `getMorphClass()`, never its class name,
     * and a string is a morph alias. `'party'` (or the Party model) names
     * the package's Party, which a string actor at the call site is. An
     * empty role never violates one: an anonymous actor is unknown, not
     * something else, and a role left out isn't said. Saying a role again
     * replaces its types, as `->where()` replaces a parameter's pattern.
     *
     * Checked at every publish, whatever put the role there (the call
     * site, a scope, a middleware or the default actor), and by the doctor
     * over the rows already stored.
     *
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    public function whereRole(string $role, string|array ...$types): static
    {
        $this->wheres[$role] = self::roleTypes($role, $types, "->where{$this->roleMethod($role)}() on [{$this->key()}]");

        return $this;
    }

    /**
     * Constraints an enclosing group gave, beneath the verb's own, as a
     * route group's `where` sits beneath the route's.
     *
     * @param  array<string, list<string>>  $wheres  already resolved
     *
     * @internal
     */
    public function whereRoles(array $wheres): self
    {
        $this->wheres = [...$wheres, ...$this->wheres];

        return $this;
    }

    /**
     * The types each constrained role may be, as morph aliases; empty when
     * nothing is constrained.
     *
     * @return array<string, list<string>>
     */
    public function wheres(): array
    {
        return $this->wheres;
    }

    /**
     * A role's types as the morph aliases a row stores, checked at the line
     * that names them.
     *
     * @param  array<int, string|array<int, string>>  $types
     * @return list<string>
     *
     * @internal
     */
    public static function roleTypes(string $role, array $types, string $where): array
    {
        if (! in_array($role, ActivityRoles::STORED, true)) {
            throw new InvalidArgumentException("{$where} names [{$role}], which is not a role. The roles are ".implode(', ', ActivityRoles::STORED).'.');
        }

        $types = array_merge(...array_map(fn (string|array $type) => array_values((array) $type), $types));

        if ($types === []) {
            throw new InvalidArgumentException("{$where} was given no types for the {$role}. Name at least one: a model class, a morph alias, or 'party'.");
        }

        $aliases = [];

        foreach ($types as $type) {
            $type = trim($type);

            $aliases[] = match (true) {
                $type === 'party' => (string) config('storyfeed.morph_alias', 'storyfeed.party'),
                $type === '' || $type === '*' => throw new InvalidArgumentException(
                    "{$where} was given [{$type}] for the {$role}. Name a model class, a morph alias, or 'party'; to allow any type, leave the {$role} unconstrained.",
                ),
                class_exists($type) && is_a($type, Model::class, true) => (new $type)->getMorphClass(),
                class_exists($type) || str_contains($type, '\\') => throw new InvalidArgumentException(
                    "{$where} was given [{$type}] for the {$role}, which is not an Eloquent model. Name a model class, a morph alias, or 'party'.",
                ),
                default => $type,
            };
        }

        return array_values(array_unique($aliases));
    }

    /** `Actor` for `->whereActor()`, or `Role('origin', …)` for the rest. */
    private function roleMethod(string $role): string
    {
        return in_array($role, ['actor', 'object', 'target', 'context'], true) ? ucfirst($role) : "Role('{$role}', …)";
    }

    /**
     * Name the story, as `->name()` names a route: `story('checkout.confirm',
     * $order)` and `Storyfeed::route()` reference it by this name, and an
     * undefined name always throws. A name is optional; an unnamed verb is
     * recorded by its verb (`Storyfeed::activity('confirm', $order)`).
     *
     *     Story::for(Order::class)->verb('confirm')->name('checkout.confirm');
     *
     * Appends to a name already given, as `Route::name()` does, which is how
     * `Story::name('billing.')->group()` prefixes the names inside it. A
     * name names one key, so a definition for several types can't take one:
     * name each type's verb.
     */
    public function name(string $name): self
    {
        if (count($this->objectTypes) > 1) {
            throw StoryMisconfigured::nameSpansTypes($this->source, $this->key(), $name);
        }

        if ($this->verb === '*') {
            throw StoryMisconfigured::namedFallback($this->source, $this->key(), $name);
        }

        $this->name = ($this->name ?? '').$name;

        return $this;
    }

    /**
     * The prefix of the enclosing `Story::name()` groups.
     *
     * @internal
     */
    public function prefixName(string $prefix): self
    {
        $this->namePrefix = $prefix.$this->namePrefix;

        return $this;
    }

    /**
     * Name each type's story: what `Story::resource()` does for every verb.
     *
     * @param  array<string, string>  $names  alias => name
     *
     * @internal
     */
    public function nameTypes(array $names): self
    {
        $this->typeNames = $names;

        return $this;
    }

    /** The story's name, prefixed by its groups, or null when it has none. */
    public function getName(): ?string
    {
        return $this->name === null ? null : $this->namePrefix.$this->name;
    }

    /**
     * Every name this definition gives, keyed by the `type.verb` it names.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        if ($this->name !== null) {
            return ["{$this->objectTypes[0]}.{$this->verb}" => $this->namePrefix.$this->name];
        }

        $names = [];

        foreach ($this->typeNames as $type => $name) {
            $names["{$type}.{$this->verb}"] = $this->namePrefix.$name;
        }

        return $names;
    }

    public function groups(Group ...$groups): self
    {
        $this->groups = [...$this->groups, ...$groups];

        return $this;
    }

    /**
     * Group headlines for this verb, as Group objects or a closure builder:
     *
     *     ->grouped(Group::repeat()->headline(':actor placed :count orders'))
     *     ->grouped(fn (GroupBuilder $group) => $group
     *         ->repeat(':actor placed :count orders')
     *         ->actors(':actors placed orders'))
     *
     * Both produce Group objects, so this and a Story class's groups() share
     * one concept. Appends.
     *
     * @param  Group|Closure(GroupBuilder): mixed  ...$groups
     */
    public function grouped(Group|Closure ...$groups): self
    {
        foreach ($groups as $group) {
            if ($group instanceof Closure) {
                $group($builder = new GroupBuilder);

                $this->groups(...$builder->toGroups());

                continue;
            }

            $this->groups($group);
        }

        return $this;
    }

    /**
     * Group this verb's activities per hour: an open cluster runs from the
     * top of the hour to the next, on the clock (14:59 and 15:01 are two
     * groups). See groupedPer().
     */
    public function groupedHourly(): self
    {
        return $this->groupedPer(Period::Hour);
    }

    /**
     * Group this verb's activities per calendar day, which is what every
     * verb does unless it says otherwise. Worth saying only to undo a
     * broader definition's period (`Story::fallback()->groupedWeekly()`).
     */
    public function groupedDaily(): self
    {
        return $this->groupedPer(Period::Day);
    }

    /**
     * Group this verb's activities per ISO week, Monday to Sunday:
     *
     *     Story::for(Recipe::class)->verb('publish')->groupedWeekly()
     *         ->grouped(Group::repeat()->headline(':actor published :count recipes this week'));
     */
    public function groupedWeekly(): self
    {
        return $this->groupedPer(Period::Week);
    }

    /** Group this verb's activities per calendar month. See groupedPer(). */
    public function groupedMonthly(): self
    {
        return $this->groupedPer(Period::Month);
    }

    /**
     * The calendar period this verb's groups live in, for a period chosen
     * in code; `groupedWeekly()` and the rest say the same in one word.
     *
     * The period is cut in `app.timezone` when the activity is published,
     * and resolved on the type → verb ladder, the most specific definition
     * winning, so `Story::fallback()->groupedWeekly()` changes it for every
     * verb that says nothing. A group keeps changing for its whole period:
     * each new member moves it back to the top of the feed, for a week or a
     * month. Changing a verb's period re-keys the activities published after
     * it; groups already made stay as they are.
     *
     * Verbs that should group together should share a period: a grouping
     * that doesn't key on the verb never puts a weekly and a daily verb's
     * activities in one group, even on the same day.
     */
    public function groupedPer(Period|string $period): self
    {
        if (is_string($period)) {
            $period = Period::tryFrom($period) ?? throw new InvalidArgumentException(
                "->groupedPer() on [{$this->key()}] was given '{$period}', which is not a period. Give one of "
                .implode(', ', array_map(fn (Period $case) => "'{$case->value}'", Period::cases())).'.',
            );
        }

        $this->period = $period;

        return $this;
    }

    /**
     * Mark a definition made inside `Story::for(…)`, whose group headlines
     * compile per type (`repeat.order.place`) rather than per verb.
     *
     * @internal
     */
    public function scopedToType(): self
    {
        $this->typeScoped = ! in_array('*', $this->objectTypes, true);

        return $this;
    }

    /** @internal */
    public function isTypeScoped(): bool
    {
        return $this->typeScoped;
    }

    /** @internal */
    public function template(): string|Closure|FeedHeadline|null
    {
        return $this->headline;
    }

    /** @internal */
    public function anonymousTemplate(): string|Closure|FeedHeadline|null
    {
        return $this->anonymousHeadline;
    }

    /** @internal */
    public function nounForms(): string|FeedNoun|null
    {
        return $this->noun;
    }

    /** @internal */
    public function objectActivityStreamsType(): ObjectType|string|null
    {
        return $this->activityStreamsType;
    }

    /**
     * @return list<string>|null null when missing() was never called
     *
     * @internal
     */
    public function missingRoles(): ?array
    {
        return $this->missing;
    }

    /** @internal */
    public function missingTemplate(): string|Closure|FeedHeadline|null
    {
        return $this->missingHeadline;
    }

    /** @internal */
    public function forgetsWhenMissing(): ?bool
    {
        return $this->forgetWhenMissing;
    }

    /**
     * An ISO 8601 duration, `forever`, or null when the verb said nothing.
     *
     * @internal
     */
    public function retention(): ?string
    {
        return $this->retention;
    }

    /**
     * The roles each kept row is the latest per, and the window as an ISO
     * 8601 duration; null when every row is kept.
     *
     * @return array{per: list<string>, within: string|null}|null
     *
     * @internal
     */
    public function latestKept(): ?array
    {
        return $this->keepLatest;
    }

    /**
     * The period this verb declared, or null when it said nothing.
     *
     * @internal
     */
    public function period(): ?Period
    {
        return $this->period;
    }

    /**
     * Where a queued publish goes, as declared; null when the verb said
     * nothing and a broader definition's answer stands.
     *
     * @return array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}|null
     *
     * @internal
     */
    public function queueing(): ?array
    {
        return $this->queueing === [] ? null : $this->queueing;
    }

    /**
     * Take a bound line's queue placement, what it said winning.
     *
     * @param  array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}  $queueing
     *
     * @internal
     */
    public function queueLike(array $queueing): self
    {
        $this->queueing = [...$this->queueing, ...$queueing];

        return $this;
    }

    /** @internal */
    public function actorGiven(): Model|string|null
    {
        return $this->actor;
    }

    /**
     * Mark a definition an action returned: `Class@method`, and whether the
     * action takes the request.
     *
     * @internal
     */
    public function fromAction(string $uses, bool $takesRequest): self
    {
        $this->action = $uses;
        $this->takesRequest = $takesRequest;

        return $this;
    }

    /** `App\Stories\OrderStory@place`, a message class, or null for a line or an array. */
    public function action(): ?string
    {
        return $this->action;
    }

    /** @internal */
    public function takesRequest(): bool
    {
        return $this->takesRequest;
    }

    /**
     * The parts read when no publish is happening, each reduced to a string
     * that is equal whenever the part is: what an action that takes the
     * request must return the same of, whatever the request.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function compiledParts(): array
    {
        $describe = function (mixed $value) use (&$describe): string {
            return match (true) {
                $value === null => '',
                $value instanceof Closure => ManifestClosure::fingerprint($value),
                $value instanceof FeedHeadline => "trans:{$value->key}",
                $value instanceof FeedNoun => 'noun:'.($value->translated ? 't:' : '').$value->value,
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof Group => json_encode([$value->axis, $value->template(), $value->parentTemplate()]) ?: '',
                is_array($value) => implode('|', array_map($describe, $value)),
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        };

        return array_map(fn (mixed $value) => md5($describe($value)), [
            'headline' => $this->headline,
            'anonymousHeadline' => $this->anonymousHeadline,
            'missingHeadline' => $this->missingHeadline,
            'icon' => $this->icon,
            'intent' => $this->intent,
            'type' => $this->type,
            'noun' => $this->noun,
            'activityStreamsType' => $this->activityStreamsType,
            'missing' => $this->missing === null ? null : ['[', ...$this->missing],
            'forgetWhenMissing' => $this->forgetWhenMissing,
            'retention' => $this->retention,
            'keepLatest' => $this->keepLatest === null ? null : [...$this->keepLatest['per'], '@', (string) $this->keepLatest['within']],
            'period' => $this->period,
            'queue' => $this->queueing === [] ? null : array_map(
                fn (string $key, mixed $value) => $key.'='.var_export($value, true),
                array_keys($this->queueing),
                $this->queueing,
            ),
            'groups' => $this->groups,
            'wheres' => $this->wheres === [] ? null : array_map(
                fn (string $role, array $types) => $role.'='.implode(',', $types),
                array_keys($this->wheres),
                $this->wheres,
            ),
        ]);
    }

    public function iconToken(): ?string
    {
        return $this->icon;
    }

    public function glyphIntent(): ?string
    {
        return $this->intent;
    }

    public function activityType(): ActivityType|string|null
    {
        return $this->type;
    }

    /** @return array<int, Group> */
    public function groupList(): array
    {
        return $this->groups;
    }

    /**
     * The (objectType, verb) pairs this definition authors — the shape
     * HeadlineCoverage speaks.
     *
     * @return array<int, array{0: string|null, 1: string}>
     */
    public function pairs(): array
    {
        return array_map(
            fn (string $alias) => [$alias === '*' ? null : $alias, $this->verb],
            $this->objectTypes,
        );
    }

    /** `order.place`, or `order.place, refund.place` for a list. */
    public function key(): string
    {
        return implode(', ', array_map(fn (string $alias) => "{$alias}.{$this->verb}", $this->objectTypes));
    }

    /**
     * Where the definition was written: the first frame outside the package
     * (and outside Laravel's facade), as `path:line` relative to the app.
     * The same form FeedDefinition gives closure feeds.
     *
     * @internal
     */
    public static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null
                || str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR)
                || str_ends_with($file, 'Facades'.DIRECTORY_SEPARATOR.'Facade.php')) {
                continue;
            }

            return self::relative($file).':'.($frame['line'] ?? 0);
        }

        return 'an unknown location';
    }

    private static function relative(string $file): string
    {
        try {
            $base = app()->basePath();
        } catch (\Throwable) {
            return $file;
        }

        return str_starts_with($file, $base.DIRECTORY_SEPARATOR)
            ? substr($file, strlen($base) + 1)
            : $file;
    }

    /**
     * A model class resolves through getMorphClass(), which is the value
     * actually stored in `activities.object_type` whether or not the app
     * registered a morph map — and it honours the standing rule to compare
     * morph aliases, never class names. Declaring the model class is the
     * recommended form because a rename is then an IDE-checked change, unlike
     * the string 'document'.
     */
    protected static function alias(string $objectType): string
    {
        if ($objectType === '*') {
            return '*';
        }

        if (class_exists($objectType) && is_a($objectType, Model::class, true)) {
            return (new $objectType)->getMorphClass();
        }

        return $objectType;
    }

    /**
     * A window as the ISO 8601 duration the manifest stores: `'30 days'` and
     * `CarbonInterval::days(30)` both compile to `P30D`. Months stay months,
     * so `'6 months'` is measured on the calendar when the prune runs.
     */
    protected static function window(string|DateInterval $window, string $key, string $method = 'keepFor', string $argument = ''): string
    {
        try {
            $interval = $window instanceof DateInterval
                ? CarbonInterval::instance($window)
                : CarbonInterval::make(trim($window));
        } catch (\Throwable) {
            $interval = null;
        }

        if ($interval === null || $interval->invert || $interval->totalSeconds <= 0) {
            $given = $window instanceof DateInterval ? 'the interval given' : "'{$window}'";

            throw new InvalidArgumentException(
                "->{$method}() on [{$key}] was given {$argument}{$given}, which is not a positive interval. "
                .($method === 'keepFor'
                    ? "Give one Carbon reads, like '30 days' or '6 months', or call ->keepForever()."
                    : "Give one Carbon reads, like '10 minutes' or '1 day'."),
            );
        }

        return $interval->spec();
    }

    protected static function normalizeVerb(string|FeedVerb|BackedEnum $verb): string
    {
        return match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => trim($verb),
        };
    }
}
