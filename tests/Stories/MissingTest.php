<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Story as BaseStory;
use Storyfeed\StoryDefinition;
use Storyfeed\Support\StoryManifest;
use Storyfeed\Support\TombstoneRules;
use Storyfeed\Verb;
use Workbench\App\Models\Delivery;

/*
 * ->missing(): which roles, once tombstoned, make an activity redundant.
 * Declared per verb, compiled through CompileStories into TombstoneRules.
 */

function constitutive(?string $type, string $verb): array
{
    return app(TombstoneRules::class)->constitutiveRoles($type, $verb);
}

it('defaults to the object, and to no role for a removal verb', function () {
    expect(constitutive('delivery', 'confirm'))->toBe(['object'])
        ->and(constitutive('delivery', 'delete'))->toBe([])
        // A Storyfeed\Verb case brings its AS2 type: archive is Remove, void is Undo.
        ->and(constitutive('delivery', Verb::Archive->value))->toBe([])
        ->and(constitutive('delivery', Verb::Void->value))->toBe([]);
});

it('declares roles on a registrar verb, on the type ladder', function () {
    Story::verb('turn_into')->missing('object', 'result');
    Story::for(Delivery::class)->verb('ask_about')->missing();
    Story::for('customer')->missing('target');
    Story::fallback()->missing('object', 'target');

    expect(constitutive('question', 'turn_into'))->toBe(['object', 'result'])
        ->and(constitutive('delivery', 'ask_about'))->toBe([])
        ->and(constitutive('customer', 'place'))->toBe(['target'])
        ->and(constitutive('dish', 'cook'))->toBe(['object', 'target'])
        ->and(Storyfeed::compiledStories()['missing'])->toBe([
            '*.turn_into' => ['object', 'result'],
            'delivery.ask_about' => [],
            'customer.*' => ['target'],
            '*.*' => ['object', 'target'],
        ]);
});

it('declares roles with a chained verb closure and on a fallback', function () {
    Story::for(Delivery::class)
        ->verb('route', fn (StoryDefinition $verb) => $verb->headline(':actor routed :object')->missing('origin'))
        ->fallback(fn (StoryDefinition $verb) => $verb->missing('context'));

    expect(constitutive('delivery', 'route'))->toBe(['origin'])
        ->and(constitutive('delivery', 'pack'))->toBe(['context']);
});

it('replaces the default set rather than adding to it', function () {
    Story::verb('reassign')->missing('target');

    expect(constitutive('delivery', 'reassign'))->toBe(['target'])
        ->and(constitutive('delivery', 'reassign'))->not->toContain('object');
});

it('declares roles in the array form, where an empty list means none', function () {
    Storyfeed::stories([
        'delivery.note' => ['headline' => ':actor noted :object', 'missing' => ['target']],
        'delivery.mention' => ['missing' => []],
        StoryDefinition::make('delivery.pack')->missing('instrument'),
    ]);

    expect(constitutive('delivery', 'note'))->toBe(['target'])
        ->and(constitutive('delivery', 'mention'))->toBe([])
        ->and(constitutive('delivery', 'pack'))->toBe(['instrument']);
});

it('declares roles on a Story class with missing()', function () {
    $story = new class extends BaseStory
    {
        public string|array|null $objectType = 'delivery';

        public string|FeedVerb|BackedEnum|null $verb = 'hand_over';

        public function headline(): string
        {
            return ':actor handed :object to :target';
        }

        public function missing(): ?array
        {
            return ['object', 'target'];
        }
    };

    Storyfeed::stories([StoryDefinition::fromStory($story)]);

    expect(constitutive('delivery', 'hand_over'))->toBe(['object', 'target'])
        // A class that doesn't override it keeps the default.
        ->and(StoryDefinition::fromStory(new class extends BaseStory
        {
            public string|array|null $objectType = 'delivery';

            public string|FeedVerb|BackedEnum|null $verb = 'confirm';

            public function headline(): string
            {
                return ':actor confirmed :object';
            }
        })->missingRoles())->toBeNull();
});

it('refuses a role that is not one of the seven', function () {
    Story::verb('place')->missing('object', 'customer');
})->throws(InvalidArgumentException::class, 'names [customer], which is not a role');

it('names both lines when one verb declares its roles twice', function () {
    Story::for(Delivery::class)->verb('confirm')->missing('object');
    Story::for(Delivery::class)->verb('confirm')->missing();

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'MissingTest.php:');

it('classifies the resource verbs delete and restore as removals', function () {
    Story::resource(Delivery::class);

    expect(constitutive('delivery', 'delete'))->toBe([])
        ->and(constitutive('delivery', 'restore'))->toBe([])
        ->and(constitutive('delivery', 'create'))->toBe(['object'])
        ->and(constitutive('delivery', 'update'))->toBe(['object'])
        ->and(Storyfeed::compiledStories()['missing'])->toBe(['delivery.delete' => [], 'delivery.restore' => []]);
});

it('caches the rules in the manifest, and the doctor sees them drift', function () {
    $definition = Story::verb('turn_into')->missing('object', 'result');

    Artisan::call('storyfeed:cache');

    try {
        expect(app(StoryManifest::class)->read()['missing'])->toBe(['*.turn_into' => ['object', 'result']])
            ->and(Storyfeed::doctor(['manifest'])->has('manifest.stale'))->toBeFalse();

        $definition->missing('result');

        expect(Storyfeed::doctor(['manifest'])->withCode('manifest.stale')->first()?->message)
            ->toContain('missing[*.turn_into]');
    } finally {
        Artisan::call('storyfeed:clear');
    }
});
