<?php

namespace Storyfeed\Stories;

use Closure;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryfeedManager;

/**
 * Compiles story definitions into the five registry arrays.
 *
 * This is where the layer earns its keep. Four conditions the raw registries
 * accept silently become boot failures:
 *
 *   1. A composite group with no `*.{verb}` singular entry — the second,
 *      unlisted registry a consumer only discovered from doctor output after
 *      following every documented step.
 *   2. An aggregate token the axis does not pin — the documented lie class,
 *      caught before any traffic exists rather than after.
 *   3. An unregistered axis, whose grammar would never resolve. Today that is
 *      only a doctor note, after the fact.
 *   4. Two stories authoring the same key. The arrays are last-writer-wins, so
 *      this currently picks one at random and says nothing. Every registry
 *      entry is claimed, and the error names both sources (`file:line` for
 *      the registrar and ad-hoc definitions, the class for Story classes).
 *
 * Output is closure-free unless a definition authored a closure headline
 * (the registrar allows it). Closure-free output is var_export-able into the
 * manifest; FeedHeadline and FeedNoun export themselves.
 *
 * @phpstan-type Compiled array{
 *     grammar: array<string, string|Closure|FeedHeadline>,
 *     aggregateGrammar: array<string, string>,
 *     actorlessGrammar: array<string, string|Closure|FeedHeadline>,
 *     icons: array<string, string>,
 *     glyphIntents: array<string, string>,
 *     nouns: array<string, string|FeedNoun>,
 *     objectTypes: array<string, ObjectType|string>,
 *     verbs: array<string, mixed>,
 *     missing: array<string, list<string>>,
 *     missingGrammar: array<string, string|Closure|FeedHeadline>,
 *     forget: array<string, bool>,
 *     retention: array<string, string>,
 *     actors: array<string, string>,
 *     actions: array<string, array{uses: string, request: bool, parts: array<string, string>|null}>,
 * }
 */
class CompileStories
{
    /** The registries a compile produces, in the order they are applied. */
    public const REGISTRIES = ['grammar', 'aggregateGrammar', 'actorlessGrammar', 'icons', 'glyphIntents', 'nouns', 'objectTypes', 'verbs', 'missing', 'missingGrammar', 'forget', 'retention', 'actors', 'actions'];

    /**
     * @param  array<int, Verb>  $definitions
     * @return Compiled
     */
    public function __invoke(array $definitions, StoryfeedManager $storyfeed): array
    {
        $grammar = [];
        $aggregateGrammar = [];
        $actorlessGrammar = [];
        $icons = [];
        $glyphIntents = [];
        $nouns = [];
        $objectTypes = [];
        $verbs = [];
        $missing = [];
        $missingGrammar = [];
        $forget = [];
        $retention = [];
        $actors = [];
        $actions = [];

        /** @var array<string, string> $owners registry:key => the story that authored it */
        $owners = [];

        foreach ($definitions as $definition) {
            $verb = $definition->verb;
            $source = $definition->source;

            foreach ($definition->objectTypes as $alias) {
                $key = "{$alias}.{$verb}";

                if (($template = $definition->template()) !== null) {
                    $this->claim($owners, 'grammar', $key, $source);
                    $grammar[$key] = $template;
                }

                if (($anonymous = $definition->anonymousTemplate()) !== null) {
                    $this->claim($owners, 'actorlessGrammar', $key, $source);
                    $actorlessGrammar[$key] = $anonymous;
                }

                if (($icon = $definition->iconToken()) !== null) {
                    $this->claim($owners, 'icons', $key, $source);
                    $icons[$key] = $icon;
                }

                if (($intent = $definition->glyphIntent()) !== null) {
                    $this->claim($owners, 'glyphIntents', $key, $source);
                    $glyphIntents[$key] = $intent;
                }

                if (($noun = $definition->nounForms()) !== null) {
                    $nounKey = $this->nounKey($alias, $verb, $source);
                    $this->claim($owners, 'nouns', $nounKey, $source);
                    $nouns[$nounKey] = $noun;
                }

                // The roles a tombstone makes the activity redundant through,
                // on the same `type.verb` ladder: `->missing()` declares the
                // whole set, so it is one entry, never merged.
                if (($roles = $definition->missingRoles()) !== null) {
                    $this->claim($owners, 'missing', $key, $source);
                    $missing[$key] = $roles;
                }

                if (($missingTemplate = $definition->missingTemplate()) !== null) {
                    $this->claim($owners, 'missingGrammar', $key, $source);
                    $missingGrammar[$key] = $missingTemplate;
                }

                // Only what a definition said: one that says nothing leaves
                // the key to a wildcard, and `false` overrides one.
                if (($forgets = $definition->forgetsWhenMissing()) !== null) {
                    $this->claim($owners, 'forget', $key, $source);
                    $forget[$key] = $forgets;
                }

                // How long the verb's activities are kept: an ISO 8601
                // duration, or `forever`. Unsaid, the global window stands.
                if (($window = $definition->retention()) !== null) {
                    $this->claim($owners, 'retention', $key, $source);
                    $retention[$key] = $window;
                }

                // A fixed actor. An action that takes the request chooses its
                // actor at each publish, so what the blank one gave is moot.
                if (! $definition->takesRequest() && ($actor = $definition->actorGiven()) !== null) {
                    if (! is_string($actor)) {
                        throw StoryMisconfigured::compiledModelActor($source);
                    }

                    $this->claim($owners, 'actors', $key, $source);
                    $actors[$key] = $actor;
                }

                // `verb → Class@method`, as route:cache stores `action.uses`,
                // so nothing downstream reflects over a Story class again.
                if (($uses = $definition->action()) !== null) {
                    $this->claim($owners, 'actions', $key, $source);
                    $actions[$key] = [
                        'uses' => $uses,
                        'request' => $definition->takesRequest(),
                        'parts' => $definition->takesRequest() ? $definition->compiledParts() : null,
                    ];
                }

                if (($objectType = $definition->objectActivityStreamsType()) !== null) {
                    if ($alias === '*') {
                        throw StoryMisconfigured::wildcardObjectType($source);
                    }

                    $this->claim($owners, 'objectTypes', $alias, $source);
                    $objectTypes[$alias] = $objectType;
                }
            }

            // `order.*` is a wildcard KEY, not a verb: declaring `*` would put
            // it in storyfeed:verbs and let it satisfy verbs.strict.
            if ($verb !== '*') {
                // Registered even when $type is null, reusing the verb
                // registry's own fallback. Without this, strict mode throws
                // UnknownVerb for every story-authored verb whose vocabulary
                // is not also in an enum — a guaranteed day-one bug report.
                // A definition without a type never erases one another
                // definition of the verb declared.
                $verbs[$verb] = $definition->activityType()
                    ?? $verbs[$verb]
                    ?? StoryfeedManager::DEFAULT_VERBS[$verb]
                    ?? CoreType::Activity->value;
            }

            foreach ($definition->groupList() as $group) {
                $this->compileGroup($group, $definition, $storyfeed, $aggregateGrammar, $grammar, $owners);
            }

            $this->assertCompositeHasParentGrammar($definition, $grammar);
        }

        return [
            'grammar' => $grammar,
            'aggregateGrammar' => $aggregateGrammar,
            'actorlessGrammar' => $actorlessGrammar,
            'icons' => $icons,
            'glyphIntents' => $glyphIntents,
            'nouns' => $nouns,
            'objectTypes' => $objectTypes,
            'verbs' => $verbs,
            'missing' => $missing,
            'missingGrammar' => $missingGrammar,
            'forget' => $forget,
            'retention' => $retention,
            'actors' => $actors,
            'actions' => $actions,
        ];
    }

    /**
     * The noun registry speaks three keys: `type.verb`, `type`, and `*`.
     * A fallback definition (`order.*`) is the type's noun; `*.*` is the
     * global one. There is no `*.verb` noun — a noun describes a KIND of
     * thing, and a verb only refines it — so an unscoped verb can't have one.
     */
    protected function nounKey(string $alias, string $verb, string $source): string
    {
        return match (true) {
            $verb === '*' => $alias,
            $alias === '*' => throw StoryMisconfigured::unscopedNoun($source, $verb),
            default => "{$alias}.{$verb}",
        };
    }

    /**
     * @param  array<string, string>  $aggregateGrammar
     * @param  array<string, string|Closure|FeedHeadline>  $grammar
     * @param  array<string, string>  $owners
     */
    protected function compileGroup(
        Group $group,
        Verb $definition,
        StoryfeedManager $storyfeed,
        array &$aggregateGrammar,
        array &$grammar,
        array &$owners,
    ): void {
        $verb = $definition->verb;
        $source = $definition->source;
        $template = $group->template();

        // Derived from the axis's compiled recipe — the same derivation the
        // doctor check and the presenter's fallback guard already use.
        $allowed = $storyfeed->aggregateTokens($group->axis);

        if ($allowed === null) {
            throw StoryMisconfigured::unknownAxis($source, $group->axis, array_keys($storyfeed->registeredAxes()));
        }

        if ($template !== null) {
            preg_match_all('/:[a-z]+/', $template, $matches);

            foreach (array_diff(array_unique($matches[0]), $allowed) as $token) {
                throw StoryMisconfigured::unpinnedToken($source, $group->axis, $token, $allowed);
            }

            foreach ($this->groupKeys($group, $definition, $storyfeed) as $key) {
                $this->claim($owners, 'aggregateGrammar', $key, $source);
                $aggregateGrammar[$key] = $template;
            }
        }

        if ($group->parentTemplate() !== null) {
            $grammar["*.{$verb}"] = $group->parentTemplate();
        }
    }

    /**
     * The aggregate keys a group headline compiles to. `axis.verb`, except
     * inside `Story::for(…)`, where it is per type (`repeat.order.place`),
     * which the read path tries first when the axis pins the object type.
     * An axis that doesn't pin it can gather several types into one group,
     * so a per-type headline would name whichever came first: that throws,
     * pointing at `Story::verb(…)->grouped()`. Row-backed axes (composite,
     * batch) keep `axis.verb`; their headline belongs to the verb already.
     *
     * Story classes and Verb::make()/for() keep `axis.verb`.
     *
     * @return list<string>
     */
    protected function groupKeys(Group $group, Verb $definition, StoryfeedManager $storyfeed): array
    {
        $verb = $definition->verb;

        if (! $definition->isTypeScoped() || $storyfeed->axis($group->axis)?->isRowBacked() === true) {
            return ["{$group->axis}.{$verb}"];
        }

        if ($verb === '*') {
            throw StoryMisconfigured::typeScopedFallbackGroup($definition->source, $definition->objectTypes[0]);
        }

        if (! $storyfeed->pinsType($group->axis, 'object')) {
            throw StoryMisconfigured::typeScopedGroup($definition->source, $group->axis, $verb, $definition->objectTypes[0]);
        }

        return array_map(fn (string $alias) => "{$group->axis}.{$alias}.{$verb}", $definition->objectTypes);
    }

    /**
     * A composite parent carries no object of its own, so `{type}.{verb}` never
     * resolves for it and it needs `*.{verb}`. Accept that entry from ANY
     * source — this story's parentHeadline(), another story declaring
     * objectType '*', or a hand-written grammar() call — because all three are
     * legitimate and the point is only that it exists.
     *
     * @param  array<string, string|Closure|FeedHeadline>  $grammar
     */
    protected function assertCompositeHasParentGrammar(Verb $definition, array $grammar): void
    {
        $composite = array_filter(
            $definition->groupList(),
            fn (Group $group) => $group->axis === 'composite',
        );

        if ($composite === []) {
            return;
        }

        if (! array_key_exists("*.{$definition->verb}", $grammar)) {
            throw StoryMisconfigured::missingParentGrammar($definition->source, $definition->verb);
        }
    }

    /**
     * @param  array<string, string>  $owners
     */
    protected function claim(array &$owners, string $registry, string $key, string $source): void
    {
        $claim = "{$registry}:{$key}";

        if (isset($owners[$claim]) && $owners[$claim] !== $source) {
            throw StoryMisconfigured::conflictingStories($key, $owners[$claim], $source);
        }

        $owners[$claim] = $source;
    }
}
