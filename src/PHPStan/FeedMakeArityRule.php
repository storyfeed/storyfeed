<?php

namespace Storyfeed\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Storyfeed\Feed;

/**
 * `CustomerFeed::make()` is checked against `CustomerFeed::__construct()`.
 *
 * WHY THIS EXISTS. A Feed subclass takes its subject as a typed constructor
 * parameter, and that is the whole scope guarantee: PHP itself refuses to build
 * an unscoped feed. But `Feed::make()` is `mixed ...$arguments` forwarded to a
 * reflected `newInstance()` — it has to be, because the constructor varies by
 * subclass and that variance IS the feature — so a static analyser sees a
 * variadic and says nothing. `new CustomerFeed()` is flagged in your editor
 * before you save; `CustomerFeed::make()` is an ArgumentCountError on the first
 * request that touches it. Same failure, different day.
 *
 * This rule closes that gap without changing the runtime: it resolves the
 * static call's class, reads the constructor it will actually reach, and checks
 * arity where the call is WRITTEN. The runtime guarantee is untouched and stays
 * the load-bearing one — this only moves the discovery earlier.
 *
 * It is deliberately arity-only. Argument TYPES are already the constructor's
 * business and PHPStan reports them at the `new` sites that dominate real code;
 * duplicating its parameter-type machinery here would bind this package to
 * internals that move between PHPStan minors, for a second opinion on something
 * PHP will refuse anyway. Arity is the case that reads as fine and is not.
 *
 * @implements Rule<StaticCall>
 */
class FeedMakeArityRule implements Rule
{
    public function __construct(private ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier || $node->name->toLowerString() !== 'make') {
            return [];
        }

        $class = $this->resolveFeedClass($node, $scope);

        if ($class === null) {
            return [];
        }

        $constructor = $class->hasConstructor() ? $class->getConstructor() : null;

        if ($constructor === null || $constructor->getDeclaringClass()->getName() === Feed::class) {
            // No constructor of its own: make() takes nothing, and PHP would
            // ignore anything passed. Not this rule's business either way.
            return [];
        }

        $parameters = $constructor->getOnlyVariant();

        $required = 0;
        $optional = 0;

        foreach ($parameters->getParameters() as $parameter) {
            $parameter->isOptional() ? $optional++ : $required++;
        }

        $variadic = $parameters->isVariadic();

        $given = 0;

        foreach ($node->getArgs() as $argument) {
            if ($argument->unpack) {
                // A spread's length is not knowable here; say nothing rather
                // than guess. PHP still enforces it at runtime.
                return [];
            }

            if ($argument->name !== null) {
                // Named arguments can fill any parameter in any order; the
                // count is still the honest signal and undersupply still
                // throws, but leave the naming itself to PHPStan's own checks.
                return [];
            }

            $given++;
        }

        $name = $class->getDisplayName();

        if ($given < $required) {
            return [
                RuleErrorBuilder::message(sprintf(
                    '%s::make() invoked with %d %s, %d required — %s::__construct() declares %s. '
                    .'A Feed takes its subject through the constructor, so this is an unscoped feed: '
                    .'it would throw ArgumentCountError on the first call.',
                    $name,
                    $given,
                    $given === 1 ? 'argument' : 'arguments',
                    $required,
                    $name,
                    $this->describe($parameters->getParameters()),
                ))
                    ->identifier('storyfeed.feedMakeArity')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        if (! $variadic && $given > $required + $optional) {
            return [
                RuleErrorBuilder::message(sprintf(
                    '%s::make() invoked with %d %s, at most %d expected — %s::__construct() declares %s.',
                    $name,
                    $given,
                    $given === 1 ? 'argument' : 'arguments',
                    $required + $optional,
                    $name,
                    $this->describe($parameters->getParameters()),
                ))
                    ->identifier('storyfeed.feedMakeArity')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * The Feed subclass this call will actually construct, or null if the call
     * is not a Feed at all — or is `static::make()` inside an abstract base,
     * where the constructor reached is not knowable from here.
     */
    private function resolveFeedClass(StaticCall $node, Scope $scope): ?ClassReflection
    {
        if (! $node->class instanceof Node\Name) {
            return null;
        }

        $name = $scope->resolveName($node->class);

        if (! $this->reflection->hasClass($name)) {
            return null;
        }

        $class = $this->reflection->getClass($name);

        // getParentClassesNames() rather than isSubclassOf(): the latter is
        // deprecated in PHPStan 2.1 and its replacement does not exist in 2.0,
        // and this package's constraint spans both.
        if (! in_array(Feed::class, $class->getParentClassesNames(), true) || $class->isAbstract()) {
            return null;
        }

        // `static::make()` promises a subclass, not this class; `self::make()`
        // and a written-out name promise exactly what they say.
        $written = $node->class->toLowerString();

        if ($written === 'static' || $written === 'parent') {
            return null;
        }

        return $class;
    }

    /**
     * @param  array<ParameterReflection>  $parameters
     */
    private function describe(array $parameters): string
    {
        if ($parameters === []) {
            return 'no parameters';
        }

        return '('.implode(', ', array_map(
            fn ($parameter) => ($parameter->isVariadic() ? '...' : '')
                .'$'.$parameter->getName()
                .($parameter->isOptional() && ! $parameter->isVariadic() ? ' = …' : ''),
            $parameters,
        )).')';
    }
}
