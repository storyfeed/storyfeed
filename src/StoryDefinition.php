<?php

namespace Storyfeed;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Grouping\Group;

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
 */
final class StoryDefinition
{
    /** @var array<int, Group> */
    protected array $groups = [];

    protected ?string $headline = null;

    protected ?string $icon = null;

    protected ActivityType|string|null $type = null;

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
     */
    public static function make(string $key, ?string $source = null): self
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw StoryMisconfigured::invalidDefinitionKey($key);
        }

        return new self([$parts[0]], $parts[1], $source ?? "ad-hoc [{$key}]");
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

        $definition = new self(array_values($aliases), $resolved, $source ?? "ad-hoc [{$aliases[0]}.{$resolved}]");

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
        $allowed = ['headline', 'icon', 'type', 'groups'];

        foreach (array_keys($spec) as $given) {
            if (! in_array($given, $allowed, true)) {
                throw StoryMisconfigured::unknownDefinitionKey($key, (string) $given, $allowed);
            }
        }

        $definition = self::make($key);

        if (isset($spec['headline'])) {
            $definition = $definition->headline((string) $spec['headline']);
        }

        if (isset($spec['icon'])) {
            $definition = $definition->icon((string) $spec['icon']);
        }

        if (isset($spec['type'])) {
            $definition = $definition->type($spec['type']);
        }

        /** @var array<int, Group> $groups */
        $groups = $spec['groups'] ?? [];

        return $definition->groups(...$groups);
    }

    public function headline(string $template): self
    {
        $this->headline = $template;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

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

    public function template(): ?string
    {
        return $this->headline;
    }

    public function iconToken(): ?string
    {
        return $this->icon;
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
