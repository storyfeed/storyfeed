<?php

namespace Storyfeed\PHPStan;

use Illuminate\Container\Container;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedManager;
use Throwable;

/**
 * A literal story name nothing defined, in `story('…')`,
 * `Storyfeed::route('…')` or `Story::has('…')`, is an error where it is
 * written.
 *
 * WHY THIS EXISTS. An undefined name throws StoryNotFound at the call, the
 * way `route()` throws RouteNotFoundException, and that runtime throw stays
 * the load-bearing guarantee. This moves the discovery into the editor and
 * CI, before the line ever runs, as the Laravel ecosystem checks route
 * names statically.
 *
 * WHERE THE NAMES COME FROM. The booted application: Larastan boots it for
 * analysis (its bootstrap.php), so routes/feed.php has loaded, or the
 * cached manifest has, and the manager knows every name. Without a booted
 * app with Storyfeed in it there is nothing to check against, and the rule
 * says nothing rather than guess. A name built at runtime isn't checked
 * either: only a constant string is known here.
 *
 * @implements Rule<CallLike>
 */
class StoryNameRule implements Rule
{
    /** @var list<string>|false|null false: not looked up yet; null: no app to ask */
    private array|false|null $names = false;

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (($call = $this->call($node, $scope)) === null) {
            return [];
        }

        $argument = $node->getArgs()[0] ?? null;

        if ($argument === null || $argument->unpack || ($names = $this->knownNames()) === null) {
            return [];
        }

        $errors = [];

        foreach ($scope->getType($argument->value)->getConstantStrings() as $name) {
            if (! in_array($name->getValue(), $names, true)) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Story [%s] not defined: %s. Name a story in routes/feed.php with ->name(), '
                    .'or use a name storyfeed:list shows.',
                    $name->getValue(),
                    $call,
                ))
                    ->identifier('storyfeed.storyName')
                    ->line($node->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * What an undefined name does to the call, when it references a story
     * by name.
     */
    private function call(Node $node, Scope $scope): ?string
    {
        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            return $node->name->toLowerString() === 'story' || $scope->resolveName($node->name) === 'story' ? 'story() would throw StoryNotFound' : null;
        }

        if ($node instanceof StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = $scope->resolveName($node->class);
            $method = $node->name->toLowerString();

            return match (true) {
                $class === Storyfeed::class && $method === 'route' => 'Storyfeed::route() would throw StoryNotFound',
                $class === Story::class && $method === 'has' => 'Story::has() is always false for it',
                default => null,
            };
        }

        if ($node instanceof MethodCall && $node->name instanceof Node\Identifier && $node->name->toLowerString() === 'route'
            && (new ObjectType(StoryfeedManager::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return 'route() would throw StoryNotFound';
        }

        return null;
    }

    /**
     * Every story name the booted application defines, or null when there
     * is no application with Storyfeed in it to ask.
     *
     * @return list<string>|null
     */
    protected function knownNames(): ?array
    {
        if ($this->names !== false) {
            return $this->names;
        }

        try {
            $app = Container::getInstance();

            return $this->names = $app->bound(StoryfeedManager::class)
                ? array_keys($app->make(StoryfeedManager::class)->storyNames())
                : null;
        } catch (Throwable) {
            return $this->names = null;
        }
    }
}
