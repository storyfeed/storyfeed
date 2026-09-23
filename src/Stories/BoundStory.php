<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;

/**
 * A one-verb Story class bound to its verb in routes/feed.php, as an
 * invokable controller is bound to its route:
 *
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);
 *
 *     Story::for(Task::class)->group(function () {
 *         Story::verb('complete', TaskWasCompleted::class);
 *     });
 *
 * The line names the verb and the types, so the class may leave `$verb` and
 * `$objectType` out; if it declares them, they must agree with the line.
 * A resource Story class is bound with `Story::resource()` instead.
 *
 * Read when stories compile, like PendingResource, so the class is only
 * instantiated then.
 *
 * @internal Made by the Story facade.
 */
final class BoundStory
{
    /**
     * @param  array<int, string>|null  $objectTypes  the scope's types; null outside one, where the class's own stand
     * @param  class-string<Story>  $class
     */
    public function __construct(
        public readonly ?array $objectTypes,
        public readonly string|FeedVerb|BackedEnum $verb,
        public readonly string $class,
        public readonly string $source,
    ) {}

    /**
     * Check the class is a one-verb Story, at the line that names it.
     *
     * @param  array<int, string>|null  $objectTypes
     */
    public static function make(?array $objectTypes, string|FeedVerb|BackedEnum $verb, string $class, string $source): self
    {
        if (! is_a($class, Story::class, true)) {
            throw StoryMisconfigured::notAOneVerbStory($source, $class);
        }

        return new self($objectTypes, $verb, $class, $source);
    }

    /** The definition the class compiles to, for the line's verb and types. */
    public function definition(): Verb
    {
        $story = new ($this->class);
        $bound = Verb::for('*', $this->verb, $this->source)->verb;

        if ($story->verb !== null && ($declared = Verb::for('*', $story->verb, $this->source)->verb) !== $bound) {
            throw StoryMisconfigured::boundVerbDiffers($this->source, $this->class, $declared, $bound);
        }

        if ($this->objectTypes !== null && $story->objectType !== null) {
            $declared = Verb::for($story->objectType, '*', $this->source)->objectTypes;
            $scope = Verb::for($this->objectTypes, '*', $this->source)->objectTypes;

            if (array_diff($declared, $scope) !== [] || array_diff($scope, $declared) !== []) {
                throw StoryMisconfigured::boundTypeDiffers($this->source, $this->class, $declared, $scope);
            }
        }

        // The class's enum case, when it has one, carries the AS2.0 type.
        return Verb::fromStory($story, $this->objectTypes, $story->verb ?? $this->verb, $this->source);
    }
}
