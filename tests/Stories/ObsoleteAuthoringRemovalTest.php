<?php

use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryfeedManager;

it('removes obsolete authoring methods and facade advertisements', function (string $method) {
    expect(method_exists(StoryfeedManager::class, $method))->toBeFalse();

    $facade = (new ReflectionClass(Storyfeed::class))->getDocComment();
    expect($facade)->not->toContain(" {$method}(");
})->with(['grammar', 'actorlessGrammar', 'aggregateGrammar', 'icons', 'glyphIntents', 'nouns', 'objectTypes']);

it('retains compiled registries and readers through modern authoring', function () {
    Story::for('delivery')->verb('confirm')
        ->headline(':actor confirmed :object')
        ->anonymousHeadline(':object was confirmed')
        ->icon('truck')->intent('success')->noun('file|files')
        ->activityStreamsType(ObjectType::Document)
        ->grouped(Group::repeat()->headline(':actor confirmed :count files'));
    Story::for('delivery')->noun('delivery|deliveries');

    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::actorlessTemplate('delivery', 'confirm'))->toBe(':object was confirmed')
        ->and(Storyfeed::icon('delivery', 'confirm'))->toBe('truck')
        ->and(Storyfeed::glyphIntent('delivery', 'confirm'))->toBe('success')
        ->and(Storyfeed::noun('delivery', 'confirm'))->toBe('file|files')
        ->and(Storyfeed::noun('delivery', 'archive'))->toBe('delivery|deliveries')
        ->and(Storyfeed::objectType('delivery'))->toBe(ObjectType::Document)
        ->and(Storyfeed::aggregateTemplateKey('repeat', 'confirm', 'delivery'))->toBe('repeat.delivery.confirm');

    $compiled = Storyfeed::compiledStories();
    expect($compiled)->toHaveKeys(['grammar', 'actorlessGrammar', 'aggregateGrammar', 'icons', 'glyphIntents', 'nouns', 'objectTypes']);

    Storyfeed::useCompiledStories($compiled);
    Storyfeed::compileStories();
    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object');
});
