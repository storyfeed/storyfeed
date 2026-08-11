<?php

namespace Storyfeed;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\MorphResolver;
use Throwable;

class StoryfeedManager
{
    protected ?Closure $actorResolver = null;

    /** @var array<string, string|Closure> */
    protected array $grammar = [];

    /** @var array<string, string> */
    protected array $icons = [];

    /** @var array<string, ActivityType|string> */
    protected array $verbs = self::DEFAULT_VERBS;

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
    ): Activity {
        return $this->activity($verb, $object)
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
     * Begin composing a feed query.
     */
    public function feed(): FeedBuilder
    {
        return new FeedBuilder;
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
     * @param  array<string, ActivityType|string>|class-string  $verbs
     */
    public function verbs(array|string $verbs, bool $merge = true): static
    {
        $resolved = is_string($verbs) ? $this->expandVerbEnum($verbs) : $verbs;

        $normalized = [];

        foreach ($resolved as $verb => $type) {
            $normalized[$verb] = $this->normalizeTerm($type, ActivityType::class);
        }

        $this->verbs = $merge ? [...$this->verbs, ...$normalized] : $normalized;

        return $this;
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
        return $this->resolve($this->grammar, $type, $verb);
    }

    /**
     * Resolve the icon for an object type + verb (same order as grammar).
     */
    public function icon(?string $type, string $verb): ?string
    {
        return $this->resolve($this->icons, $type, $verb);
    }

    /**
     * The AS2.0 activity type for a verb: an enum when known, a raw string
     * for extension types, null when unmapped.
     */
    public function activityType(string $verb): ActivityType|string|null
    {
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
        return $this->grammar;
    }

    /** @return array<string, string> */
    public function registeredIcons(): array
    {
        return $this->icons;
    }

    /** @return array<string, ActivityType|string> */
    public function registeredVerbs(): array
    {
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

    protected function party(string $name): Party
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
     * @template TValue
     *
     * @param  array<string, TValue>  $registry
     * @return TValue|null
     */
    protected function resolve(array $registry, ?string $type, string $verb)
    {
        $keys = $type === null
            ? ["*.{$verb}", '*.*']
            : ["{$type}.{$verb}", "{$type}.*", "*.{$verb}", '*.*'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $registry)) {
                return $registry[$key];
            }
        }

        return null;
    }
}
