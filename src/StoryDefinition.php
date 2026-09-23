<?php

namespace Storyfeed;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;

/**
 * A story as data — the normalized form every authoring path funnels into.
 *
 * Three ways to author one activity type, one compiler:
 *
 *   Storyfeed::stories([
 *       DocumentWasUploaded::class,                       // a class
 *
 *       StoryDefinition::make('comment.comment')          // fluent, ad-hoc
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
 * returns a StoryDefinition that is already registered, the way `Route::get()`
 * returns a Route already in the collection. That is why it is a MUTABLE
 * builder: a definition is registered when it is made and configured after.
 *
 * Every definition knows where it was written (`routes/feed.php:14`), so two
 * definitions of one key fail naming both lines.
 */
final class StoryDefinition
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

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public static function fromArray(string $key, array $spec): self
    {
        $allowed = ['headline', 'anonymousHeadline', 'icon', 'intent', 'type', 'noun', 'activityStreamsType', 'groups'];

        foreach (array_keys($spec) as $given) {
            if (! in_array($given, $allowed, true)) {
                throw StoryMisconfigured::unknownDefinitionKey($key, (string) $given, $allowed);
            }
        }

        $definition = self::make($key, "array [{$key}]");

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
