<?php

/**
 * The Boost guideline and skill are the agent edition of the docs: a coding
 * agent reads them instead of docs.storyfeed.dev, so an API they never name
 * is an API agents will not use, or will guess at. This fails when a public
 * Storyfeed facade method or an app-constructed body type is added without
 * either file naming it. Add the mention; only genuinely internal surface
 * goes in the allow-lists below, each with its reason.
 */
function boostText(): string
{
    $root = dirname(__DIR__).'/resources/boost';

    return file_get_contents($root.'/guidelines/core.blade.php')
        .file_get_contents($root.'/skills/storyfeed-development/SKILL.md');
}

/**
 * @return list<string>
 */
function facadeMethods(): array
{
    $source = file_get_contents(dirname(__DIR__).'/src/Facades/Storyfeed.php');
    preg_match_all('/@method static [^(\n]*?\b(\w+)\(/', $source, $matches);

    return array_values(array_unique([...$matches[1], 'fake']));
}

it('names every public facade method an app would use', function () {
    // Internals and read-side lookups for core, the renderers and the doctor.
    $internal = [
        // Resolved-definition lookups the read path and doctor use.
        'actorlessTemplate' => 'definition lookup',
        'actorlessTemplateKey' => 'definition lookup',
        'template' => 'definition lookup',
        'templateKey' => 'definition lookup',
        'missingTemplate' => 'definition lookup',
        'aggregateTemplate' => 'definition lookup',
        'aggregateTemplateKey' => 'definition lookup',
        'icon' => 'definition lookup',
        'iconKey' => 'definition lookup',
        'glyphIntent' => 'definition lookup',
        'glyphIntentKey' => 'definition lookup',
        'noun' => 'definition lookup',
        'dataCasts' => 'definition lookup',
        'activityType' => 'definition lookup',
        'activityTypeValue' => 'definition lookup',
        'objectType' => 'definition lookup',
        'objectTypeValue' => 'definition lookup',
        'declaredVerb' => 'definition lookup',
        'declaredParties' => 'definition lookup',
        'party' => 'resolves a declared party for the payload',
        'aggregateTokens' => 'grammar lookup',
        'pinsType' => 'grammar lookup',
        'isBundleable' => 'grouping lookup',
        // Registry readers: introspection for the doctor and `storyfeed:list`.
        'registeredActorlessGrammar' => 'registry reader',
        'registeredNouns' => 'registry reader',
        'registeredGrammar' => 'registry reader',
        'registeredAggregateGrammar' => 'registry reader',
        'registeredIcons' => 'registry reader',
        'registeredGlyphIntents' => 'registry reader',
        'registeredVerbs' => 'registry reader',
        'registeredObjectTypes' => 'registry reader',
        'registeredFeeds' => 'registry reader',
        'registeredHealers' => 'registry reader',
        'registeredStories' => 'registry reader',
        'registeredAxes' => 'registry reader',
        'feedNames' => 'registry reader',
        'feedNameFor' => 'registry reader',
        'feedDefinition' => 'registry reader',
        'checkNames' => 'registry reader',
        'storyNames' => 'registry reader',
        'storyNameFor' => 'registry reader',
        'namedStory' => 'registry reader',
        'hasNamedStory' => 'registry reader; `Story::has()` is the app-facing check',
        'hasStory' => 'registry reader',
        'boundVerb' => 'registry reader',
        'storyVerb' => 'registry reader',
        'storyActions' => 'registry reader',
        'storyActors' => 'registry reader',
        'storyDefinitions' => 'registry reader',
        'compiledStories' => 'registry reader',
        'axis' => 'grouping engine internals',
        'aggregateAxes' => 'grouping engine internals',
        'rowBackedBuckets' => 'grouping engine internals',
        'uncuratedBuckets' => 'grouping engine internals',
        'fallbackAxis' => 'grouping engine internals',
        'axesApplicableTo' => 'grouping engine internals',
        'possibleAggregatePairs' => 'grouping engine internals',
        // Boot plumbing driven by `storyfeed:cache`, not called by apps.
        'compileStories' => 'compiled-story cache plumbing',
        'useCompiledStories' => 'compiled-story cache plumbing',
        // Reads of state the manager hands to the fake.
        'requiresFeedableMorphMap' => 'reader for requireFeedableMorphMap(), which is named',
        'usesStorage' => 'reader for withoutStorage(), which is named',
        'resolveActor' => 'reader for resolveActorUsing(), which is named',
    ];

    $text = boostText();

    // `Storyfeed::name(` is the form an agent copies. The assert helpers live
    // on the fake, so a bare `assertQueued(` counts too.
    $missing = collect(facadeMethods())
        ->reject(fn (string $method) => array_key_exists($method, $internal))
        ->reject(fn (string $method) => str_contains($text, 'Storyfeed::'.$method.'(')
            || (str_starts_with($method, 'assert') && str_contains($text, $method.'(')))
        ->values()
        ->all();

    expect($missing)->toBe([], 'Name these Storyfeed:: methods in resources/boost (guideline or skill), or allow-list them as internal with a reason: '.implode(', ', $missing));

    // An allow-list entry for a method the facade no longer has is stale.
    expect(array_values(array_diff(array_keys($internal), facadeMethods())))->toBe([]);
});

it('names every body type an app constructs', function () {
    // Concerns hold shared setters; nothing here is a body type.
    $internal = ['Concerns' => 'traits shared by body types, not bodies'];

    $bodies = collect(glob(dirname(__DIR__).'/src/Body/*'))
        ->map(fn (string $path) => basename($path, '.php'))
        ->reject(fn (string $name) => array_key_exists($name, $internal))
        ->values();

    expect($bodies)->not->toBeEmpty();

    $text = boostText();

    $missing = $bodies->reject(fn (string $name) => preg_match('/`(?:Storyfeed\\\\Body\\\\)?'.$name.'\b/', $text))->values()->all();

    expect($missing)->toBe([], 'Name these body types in resources/boost: '.implode(', ', $missing));
});
