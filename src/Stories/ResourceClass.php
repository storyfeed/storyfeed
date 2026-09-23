<?php

namespace Storyfeed\Stories;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Storyfeed\Exceptions\StoryMisconfigured;

/**
 * A resource Story class, read the way a controller is: every public method
 * is an action, and each action is a verb.
 *
 *     Story::resource(Order::class, OrderStory::class);
 *
 *     class OrderStory
 *     {
 *         public function create(Verb $verb): Verb { return $verb->headline(':actor placed :object'); }
 *         public function confirmPayment(): string { return ':actor confirmed payment for :object'; }
 *     }
 *
 * THE CLASS EXTENDS NOTHING. It declares, and nothing instantiates it at a
 * call site, as nothing calls a controller: the verb is the public handle,
 * and `Storyfeed::record('confirm_payment', $order)` addresses it. So it
 * carries no publishing API, and no method name is reserved.
 *
 * THE RULES, all checked when stories compile:
 *
 *   - Every public, non-static method declared on the class (or a trait it
 *     uses) is an action. The constructor and `__*` methods aren't. A helper
 *     is protected or private.
 *   - The declared return type is `Verb`, `string` (the headline alone) or
 *     `array` (the array form). Anything else, or none, is an error naming
 *     the method, so a helper left public fails loudly.
 *   - The verb is the method name, snake-cased: `confirmPayment()` stores
 *     `confirm_payment`, `pay()` stores `pay`. Nothing else is mapped.
 *
 * WHEN AN ACTION RUNS. Once, when stories compile, with a fresh blank
 * request, and what it returns is what the feed reads, lists, checks and
 * caches. An action that takes `Illuminate\Http\Request` also runs at each
 * publish of its verb, and only its `->actor()` is used there; at the first
 * job dispatched during a request too, whose worker gets what it chose
 * (StoryfeedManager::carriedActions). Never the
 * container's request at compile: stories compile in `booted()`, after the
 * HTTP kernel has bound the live one, so it would bake the first request
 * into the registries, per request under FPM and for a worker's lifetime
 * under Octane.
 *
 * @internal
 */
final class ResourceClass
{
    /** The return types an action may declare. */
    private const RETURNS = [Verb::class, 'string', 'array'];

    /**
     * The actions of a class, keyed by the verb each stores.
     *
     * @param  class-string  $class
     * @return array<string, array{method: string, request: bool}>
     */
    public static function actions(string $class): array
    {
        $actions = [];
        $reflection = new ReflectionClass($class);
        $class = $reflection->getName();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isConstructor()
                || str_starts_with($method->name, '__')
                || $method->class !== $class) {
                continue;
            }

            $return = $method->getReturnType();

            if (! $return instanceof ReflectionNamedType
                || $return->allowsNull()
                || ! in_array($return->getName(), self::RETURNS, true)) {
                throw StoryMisconfigured::actionReturn(self::uses($class, $method->name), self::describe($return));
            }

            $verb = Str::snake($method->name);

            if (isset($actions[$verb])) {
                throw StoryMisconfigured::actionCollision($class, $actions[$verb]['method'], $method->name, $verb);
            }

            $actions[$verb] = ['method' => $method->name, 'request' => self::takesRequest($class, $method)];
        }

        return $actions;
    }

    /**
     * Run an action on a definition and return it configured.
     *
     * @param  class-string  $class
     */
    public static function run(string $class, string $method, Verb $verb, ?Request $request = null): Verb
    {
        $uses = self::uses($class, $method);

        $result = app()->call([app($class), $method], [
            Verb::class => $verb,
            Request::class => $request ?? Request::create('/'),
        ]);

        return match (true) {
            $result === $verb => $verb,
            $result instanceof Verb => throw StoryMisconfigured::actionReturnedAnotherVerb($uses),
            is_string($result) => $verb->headline($result),
            is_array($result) => $verb->fill($result, $uses),
            default => throw StoryMisconfigured::actionReturn($uses, get_debug_type($result)),
        };
    }

    private static function describe(?ReflectionType $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof ReflectionNamedType => ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '').$type->getName(),
            $type instanceof ReflectionUnionType => implode('|', array_map(self::describe(...), $type->getTypes())),
            $type instanceof ReflectionIntersectionType => implode('&', array_map(self::describe(...), $type->getTypes())),
            default => 'an unnamed type',
        };
    }

    /** `App\Stories\OrderStory@place`, the form `route:list` gives `action.uses`. */
    public static function uses(string $class, string $method): string
    {
        return "{$class}@{$method}";
    }

    /**
     * Whether the action takes the request. Only `Illuminate\Http\Request`
     * itself: a form request resolved from the container would validate
     * the live request when stories compile.
     */
    private static function takesRequest(string $class, ReflectionMethod $method): bool
    {
        $takes = false;

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if ($name === Request::class) {
                $takes = true;
            } elseif (is_a($name, \Symfony\Component\HttpFoundation\Request::class, true)) {
                throw StoryMisconfigured::actionRequestType(self::uses($class, $method->name), $name);
            }
        }

        return $takes;
    }
}
