<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;

/**
 * The registrar behind the `Story` facade: Route-style definitions of what
 * each activity says.
 *
 *     use App\Models\Order;
 *     use Storyfeed\Facades\Story;
 *
 *     Story::for(Order::class)->group(function () {
 *         Story::verb('place')->headline(':actor placed :object[ with :target]')->icon('shopping-bag');
 *         Story::verb('complete')->headline(':actor completed :object')->icon('receipt');
 *         Story::fallback()->icon('receipt');                // order.*
 *     });
 *
 *     Story::verb('place')->grouped(fn ($group) => $group->repeat(':actor placed :count orders'));   // *.place
 *     Story::fallback()->icon('activity');                   // *.*
 *
 *     Story::resource(Document::class)->except('restore');   // created, updated, deleted
 *     Story::resource(Order::class, OrderStory::class);      // the four, plus each OrderStory action
 *
 * WHAT IT IS. A front door onto {@see Verb}. Every call makes a
 * definition, registers it with the manager at once (the way `Route::get()`
 * returns a Route already in the collection), and hands it back to be
 * configured. Definitions compile through CompileStories beside Story
 * classes, so the class form, the array form and this one produce the same
 * registries, and the same compile-time guards apply to all three.
 *
 * SCOPES ARE A STACK, pushed by `Story::for(…)->group(fn)` and popped in
 * `finally`, which is how Laravel's router does route groups. Scopes don't
 * nest: a verb has one object-type scope.
 *
 * `Storyfeed::` stays the facade for recording and reading; this one only
 * defines. One facade per concern, as `Route`, `Schedule` and `Broadcast` are.
 */
class Registrar
{
    /** @var list<array<int, string>> the object types of each open group() */
    protected array $scopes = [];

    /**
     * Scope definitions to one or more object types: a model class (resolved
     * through its morph alias), an alias string, or a list of either.
     *
     * @param  string|array<int, string>  $objectType
     */
    public function for(string|array $objectType): TypeScope
    {
        if ($this->scopes !== []) {
            throw StoryMisconfigured::nestedScope();
        }

        return new TypeScope($this, array_values((array) $objectType));
    }

    /**
     * Define a verb: inside a `group()` for the scope's object types, and
     * outside one for any object type (`*.verb`), which is also where group
     * headlines and a composite parent's headline belong.
     */
    public function verb(string|FeedVerb|BackedEnum $verb): Verb
    {
        return $this->define(end($this->scopes) ?: ['*'], $verb);
    }

    /**
     * The fallback for every verb: `type.*` inside a `group()`, `*.*` outside
     * one. It is `Route::fallback()` for headlines, icons and intents.
     */
    public function fallback(): Verb
    {
        return $this->define(end($this->scopes) ?: ['*'], '*');
    }

    /**
     * Define the lifecycle verbs of a model in one line (create, update,
     * delete, restore), as `Route::resource()` defines a controller's
     * actions. Narrow them with `->only()` / `->except()`.
     *
     * With a resource Story class, every public method of it is a verb too:
     * `Story::resource(Order::class, OrderStory::class)`.
     *
     * @param  string|array<int, string>  $objectType  a model class, a morph alias, or a list
     * @param  class-string|null  $class  a resource Story class
     */
    public function resource(string|array $objectType, ?string $class = null): PendingResource
    {
        $resource = new PendingResource($objectType, Verb::caller(), $class);

        app(StoryfeedManager::class)->stories([$resource]);

        return $resource;
    }

    /**
     * Run a group closure with the scope's object types pushed.
     *
     * @param  array<int, string>  $objectTypes
     *
     * @internal Use Story::for(…)->group(…).
     */
    public function scoped(array $objectTypes, Closure $callback, TypeScope $scope): void
    {
        $this->scopes[] = $objectTypes;

        try {
            $callback($scope);
        } finally {
            array_pop($this->scopes);
        }
    }

    /**
     * Make a definition, register it, and hand it back to be configured.
     *
     * @param  array<int, string>  $objectTypes
     *
     * @internal
     */
    public function define(array $objectTypes, string|FeedVerb|BackedEnum $verb): Verb
    {
        $definition = Verb::for($objectTypes, $verb, Verb::caller())->scopedToType();

        app(StoryfeedManager::class)->stories([$definition]);

        return $definition;
    }
}
