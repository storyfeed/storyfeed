<?php

namespace Storyfeed\Actions;

use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryDefinition;
use Storyfeed\StoryfeedManager;

/**
 * Compiles story definitions into the four registry arrays.
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
 *      this currently picks one at random and says nothing.
 *
 * Output is deliberately closure-free (headlines are typed `string`), which is
 * what makes the compiled arrays var_export-able and therefore cacheable into a
 * manifest. Closures remain legal via the hand-written registry path.
 */
class CompileStories
{
    /**
     * @param  array<int, StoryDefinition>  $definitions
     * @return array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}
     */
    public function __invoke(array $definitions, StoryfeedManager $storyfeed): array
    {
        $grammar = [];
        $aggregateGrammar = [];
        $icons = [];
        $verbs = [];

        /** @var array<string, string> $owners key => the story that authored it */
        $owners = [];

        foreach ($definitions as $definition) {
            $verb = $definition->verb;
            $source = $definition->source;

            foreach ($definition->objectTypes as $alias) {
                $key = "{$alias}.{$verb}";

                if ($definition->template() !== null) {
                    $this->claim($owners, $key, $source);
                    $grammar[$key] = $definition->template();
                }

                if ($definition->iconToken() !== null) {
                    $icons[$key] = $definition->iconToken();
                }
            }

            // Registered even when $type is null, reusing the verb registry's
            // own fallback. Without this, strict mode throws UnknownVerb for
            // every story-authored verb whose vocabulary is not also in an
            // enum — a guaranteed day-one bug report.
            $verbs[$verb] = $definition->activityType()
                ?? StoryfeedManager::DEFAULT_VERBS[$verb]
                ?? CoreType::Activity->value;

            foreach ($definition->groupList() as $group) {
                $this->compileGroup($group, $definition, $storyfeed, $aggregateGrammar, $grammar, $owners);
            }

            $this->assertCompositeHasParentGrammar($definition, $grammar);
        }

        return [
            'grammar' => $grammar,
            'aggregateGrammar' => $aggregateGrammar,
            'icons' => $icons,
            'verbs' => $verbs,
        ];
    }

    /**
     * @param  array<string, string>  $aggregateGrammar
     * @param  array<string, string>  $grammar
     * @param  array<string, string>  $owners
     */
    protected function compileGroup(
        Group $group,
        StoryDefinition $definition,
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

            $key = "{$group->axis}.{$verb}";
            $this->claim($owners, $key, $source);
            $aggregateGrammar[$key] = $template;
        }

        if ($group->parentTemplate() !== null) {
            $grammar["*.{$verb}"] = $group->parentTemplate();
        }
    }

    /**
     * A composite parent carries no object of its own, so `{type}.{verb}` never
     * resolves for it and it needs `*.{verb}`. Accept that entry from ANY
     * source — this story's parentHeadline(), another story declaring
     * objectType '*', or a hand-written grammar() call — because all three are
     * legitimate and the point is only that it exists.
     *
     * @param  array<string, string>  $grammar
     */
    protected function assertCompositeHasParentGrammar(StoryDefinition $definition, array $grammar): void
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
    protected function claim(array &$owners, string $key, string $source): void
    {
        if (isset($owners[$key]) && $owners[$key] !== $source) {
            throw StoryMisconfigured::conflictingStories($key, $owners[$key], $source);
        }

        $owners[$key] = $source;
    }
}
