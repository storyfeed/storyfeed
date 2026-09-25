<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\FeedNoun;

/**
 * Definitions for one object type (or a list of them), returned by
 * `Story::for()`:
 *
 *     Story::for(Order::class)->group(function () {
 *         Story::verb('place')->headline(':actor placed :object');
 *     });
 *
 *     Story::for(Order::class)->verb('place')->headline(':actor placed :object');
 *
 *     Story::for(Order::class)
 *         ->verb('place', fn (Verb $verb) => $verb->headline(':actor placed :object'))
 *         ->verb('complete', fn (Verb $verb) => $verb->headline(':actor completed :object'));
 *
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);
 *
 *     Story::for(MenuItem::class)->noun('dish|dishes');
 *
 * The group closure also receives this scope, for anyone who prefers
 * `fn (TypeScope $order) => $order->verb('place')`.
 */
final class TypeScope
{
    /**
     * @param  array<int, string>  $objectTypes  model classes or morph aliases, resolved per definition
     */
    public function __construct(
        private readonly Registrar $manager,
        public readonly array $objectTypes,
    ) {}

    /**
     * Run the closure with this scope open: every `Story::verb()` and
     * `Story::fallback()` inside it defines for these object types.
     *
     * @param  Closure(TypeScope): mixed  $callback
     */
    public function group(Closure $callback): self
    {
        $this->manager->scoped($this->objectTypes, $callback, $this);

        return $this;
    }

    /**
     * Define a verb for these object types. Without a closure, returns the
     * verb's definition to configure; with one, configures it and returns
     * this scope, so more `->verb()` calls chain.
     *
     * With a message class, binds the class to the verb, as a route
     * binds an invokable controller, and returns this scope:
     * `->verb('complete', TaskWasCompleted::class)`.
     *
     * @template TConfigure of (Closure(Verb): mixed)|string|null
     *
     * @param  TConfigure  $configure
     * @return (TConfigure is null ? Verb : self)
     */
    public function verb(string|FeedVerb|BackedEnum $verb, Closure|string|null $configure = null): Verb|self
    {
        if (is_string($configure)) {
            $this->manager->bind($this->objectTypes, $verb, $configure);

            return $this;
        }

        $definition = $this->manager->define($this->objectTypes, $verb);

        if ($configure === null) {
            return $definition;
        }

        $configure($definition);

        return $this;
    }

    /**
     * The fallback for these object types, `type.*`. Without a closure,
     * returns its definition; with one, configures it and returns this scope.
     *
     * @template TConfigure of (Closure(Verb): mixed)|null
     *
     * @param  TConfigure  $configure
     * @return (TConfigure is null ? Verb : self)
     */
    public function fallback(?Closure $configure = null): Verb|self
    {
        $definition = $this->manager->define($this->objectTypes, '*');

        if ($configure === null) {
            return $definition;
        }

        $configure($definition);

        return $this;
    }

    /**
     * The plural forms of the thing these types are, `'dish|dishes'`, used
     * where a group can't name one entity. Both forms are required.
     */
    public function noun(string|FeedNoun $noun): self
    {
        $this->manager->define($this->objectTypes, '*')->noun($noun);

        return $this;
    }

    /**
     * The roles every verb on these types is about, `type.*`: once one is a
     * tombstone, the activity is redundant. Replaces the default set (the
     * object); with no roles, none. A verb's own `->missing()` wins.
     */
    public function missing(string ...$roles): self
    {
        $this->manager->define($this->objectTypes, '*')->missing(...$roles);

        return $this;
    }

    /**
     * The AS2.0 object type these types serialize as — the registry form of
     * HasActivityStreamsType.
     */
    public function activityStreamsType(ObjectType|string $type): self
    {
        $this->manager->define($this->objectTypes, '*')->activityStreamsType($type);

        return $this;
    }
}
