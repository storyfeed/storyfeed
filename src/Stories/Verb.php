<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\ManifestClosure;

/**
 * A story as data — the normalized form every authoring path funnels into.
 *
 * Three ways to author one activity type, one compiler:
 *
 *   Storyfeed::stories([
 *       DocumentWasUploaded::class,                       // a class
 *
 *       Verb::make('comment.comment')                     // fluent, ad-hoc
 *           ->headline(':actor commented on :target'),
 *
 *       'member.join' => [                                // array, ad-hoc
 *           'headline' => ':actor joined :target',
 *       ],
 *   ]);
 *
 * The ad-hoc forms are the `Storage::build()` analogue: an unnamed story that
 * still runs through the same machinery, for the cases where a whole class is
 * ceremony. Because CompileStories consumes only this type, the class form and
 * the class-free forms are provably identical — a test asserts the compiled
 * registries come out byte-for-byte the same.
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
    use Conditionable;

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

    /** Per publish: who acted, when the call site and `Storyfeed::as()` didn't say. */
    protected Model|string|null $actor = null;

    /** `App\Stories\OrderStory@place`: the action this definition came from. */
    protected ?string $action = null;

    /** Whether that action takes the request, and so runs again at each publish. */
    protected bool $takesRequest = false;

    /** Made by `Story::for(…)`: its group headlines are keyed per type. */
    protected bool $typeScoped = false;

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

    public static function fromStory(string|Story $story): self
    {
        $instance = is_string($story) ? new $story : $story;
        $class = $instance::class;

        if ($instance->objectType === null) {
            throw StoryMisconfigured::missingObjectType($class);
        }

        if ($instance->verb === null) {
            throw StoryMisconfigured::missingVerb($class);
        }

        $definition = self::for($instance->objectType, $instance->verb, $class)
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

        return $definition;
    }

    /** The keys the array form accepts. */
    public const ARRAY_KEYS = ['headline', 'anonymousHeadline', 'icon', 'intent', 'type', 'noun', 'activityStreamsType', 'missing', 'missingHeadline', 'forgetWhenMissing', 'actor', 'groups'];

    /**
     * @param  array<string, mixed>  $spec
     */
    public static function fromArray(string $key, array $spec): self
    {
        return self::make($key, "array [{$key}]")->fill($spec, $key);
    }

    /**
     * Configure from the array form: what `'type.verb' => [...]` and an
     * action returning an array both say.
     *
     * @param  array<string, mixed>  $spec
     *
     * @internal
     */
    public function fill(array $spec, string $name): self
    {
        foreach (array_keys($spec) as $given) {
            if (! in_array($given, self::ARRAY_KEYS, true)) {
                throw StoryMisconfigured::unknownDefinitionKey($name, (string) $given, self::ARRAY_KEYS);
            }
        }

        $definition = $this;

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

        if (isset($spec['actor'])) {
            /** @var Model|string $actor */
            $actor = $spec['actor'];
            $definition = $definition->actor($actor);
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
     * @param  string|FeedHeadline|Closure(Activity): (string|FeedHeadline)  $headline
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
     * @param  string|FeedHeadline|Closure(Activity): (string|FeedHeadline)  $headline
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
     * @param  string|FeedHeadline|Closure(Activity): (string|FeedHeadline)  $headline
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
     * Who acted, when the call site didn't say and no `Storyfeed::as()` scope
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

    /** `App\Stories\OrderStory@place`, or null for a definition no action returned. */
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
            'groups' => $this->groups,
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
     * GrammarCoverage speaks.
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

    protected static function normalizeVerb(string|FeedVerb|BackedEnum $verb): string
    {
        return match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => trim($verb),
        };
    }
}
