<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Story;
use Storyfeed\StoryDefinition;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\StoryManifest;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * `glyph_intent` (additive, 2026-09-09): an app-owned word beside the glyph
 * token, resolved on the token's ladder but from its own registry. Null is
 * the contract for every app that has not opted in — which is what keeps the
 * addition safe against the freeze.
 */

function publishConfirm(): array
{
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->actor($user)->publish();

    return Storyfeed::feed()->get()->toArray()['items'][0];
}

it('emits null for an app that registers bare string icons and nothing else', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    $node = publishConfirm();

    expect($node['glyph'])->toBe('bi-truck')
        ->and($node)->toHaveKey('glyph_intent')
        ->and($node['glyph_intent'])->toBeNull();
});

it('resolves the intent on the icon ladder, independently of the token', function () {
    // The reason for a second registry: the token is per type, the intent is
    // said ONCE on the wildcard. Folded into the icon value, `delivery.confirm`
    // would shadow `*.confirm` and the intent would never resolve.
    Storyfeed::grammar(['*.*' => ':actor did :object'])
        ->icons(['delivery.confirm' => 'bi-truck'])
        ->glyphIntents(['*.confirm' => 'success']);

    $node = publishConfirm();

    expect($node['glyph'])->toBe('bi-truck')
        ->and($node['glyph_intent'])->toBe('success')
        ->and(Storyfeed::glyphIntentKey('delivery', 'confirm'))->toBe('*.confirm')
        ->and(Storyfeed::iconKey('delivery', 'confirm'))->toBe('delivery.confirm');
});

it('walks type.verb → type.* → *.verb → *.* like the token does', function () {
    Storyfeed::glyphIntents([
        '*.*' => 'neutral',
        '*.confirm' => 'success',
        'delivery.*' => 'shipping',
        'delivery.confirm' => 'delivered',
    ]);

    expect(Storyfeed::glyphIntent('delivery', 'confirm'))->toBe('delivered')
        ->and(Storyfeed::glyphIntent('delivery', 'cancel'))->toBe('shipping')
        ->and(Storyfeed::glyphIntent('order', 'confirm'))->toBe('success')
        ->and(Storyfeed::glyphIntent('order', 'cancel'))->toBe('neutral')
        ->and(Storyfeed::glyphIntent(null, 'confirm'))->toBe('success')
        ->and(Storyfeed::glyphIntent(null, 'cancel'))->toBe('neutral');
});

it('carries any app word verbatim — core owns no vocabulary', function () {
    Storyfeed::glyphIntents(['*.*' => 'brand-warm-2']);

    expect(Storyfeed::glyphIntent('delivery', 'confirm'))->toBe('brand-warm-2');
});

it('refuses a list where a map was meant, like the icon registry', function () {
    Storyfeed::glyphIntents(['success']);
})->throws(InvalidArgumentException::class, 'Storyfeed::glyphIntents()');

it('replaces the whole registry with merge: false', function () {
    Storyfeed::glyphIntents(['*.*' => 'a'])->glyphIntents(['*.confirm' => 'b'], merge: false);

    expect(Storyfeed::registeredGlyphIntents())->toBe(['*.confirm' => 'b']);
});

it('emits the intent on a group node from the same pair as its glyph', function () {
    Storyfeed::icons(['*.upload' => 'bi-cloud-arrow-up'])
        ->glyphIntents(['*.upload' => 'info']);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    foreach (range(1, 2) as $i) {
        Storyfeed::activity()->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['kind'])->toBe('group')
        ->and($item['glyph'])->toBe('bi-cloud-arrow-up')
        ->and($item['glyph_intent'])->toBe('info')
        ->and($item['children'][0]['glyph_intent'])->toBe('info');
});

it('compiles the three authoring forms to the same intent registry', function () {
    $story = new class extends Story
    {
        public string|array|null $objectType = Delivery::class;

        public string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|null $verb = 'confirm';

        public function headline(): string
        {
            return ':actor confirmed :object';
        }

        public function icon(): ?string
        {
            return 'bi-truck';
        }

        public function intent(): ?string
        {
            return 'success';
        }
    };

    $forms = [
        'class' => [$story::class],
        'fluent' => [
            StoryDefinition::make('delivery.confirm')
                ->headline(':actor confirmed :object')
                ->icon('bi-truck')
                ->intent('success'),
        ],
        'array' => [
            'delivery.confirm' => [
                'headline' => ':actor confirmed :object',
                'icon' => 'bi-truck',
                'intent' => 'success',
            ],
        ],
    ];

    foreach ($forms as $name => $stories) {
        Storyfeed::stories($stories, merge: false);

        $compiled = Storyfeed::compiledStories();

        expect($compiled['icons'])->toBe(['delivery.confirm' => 'bi-truck'], "form: {$name}")
            ->and($compiled['glyphIntents'])->toBe(['delivery.confirm' => 'success'], "form: {$name}");
    }
});

it('leaves the registry empty when no story declares an intent', function () {
    Storyfeed::stories([
        StoryDefinition::make('delivery.confirm')->headline(':actor confirmed :object')->icon('bi-truck'),
    ]);

    expect(Storyfeed::compiledStories()['glyphIntents'])->toBe([])
        ->and(Storyfeed::glyphIntent('delivery', 'confirm'))->toBeNull();
});

it('lets a hand-written registration win over a compiled one, like every registry', function () {
    Storyfeed::stories([
        StoryDefinition::make('delivery.confirm')->headline(':actor confirmed :object')->intent('success'),
    ]);
    Storyfeed::glyphIntents(['delivery.confirm' => 'overridden']);

    expect(Storyfeed::glyphIntent('delivery', 'confirm'))->toBe('overridden');
});

it('round-trips through the cached manifest and tolerates a manifest written before it existed', function () {
    $stories = [
        StoryDefinition::make('delivery.confirm')->headline(':actor confirmed :object')->intent('success'),
    ];
    Storyfeed::stories($stories);

    $manifest = app(StoryManifest::class);

    try {
        $this->artisan('storyfeed:cache')->assertSuccessful();

        // A fresh manager, seeded from the manifest rather than compiling —
        // the stories are registered again because boot always does, and
        // the manifest only ever stands in for their COMPILATION.
        app()->forgetInstance(StoryfeedManager::class);
        Storyfeed::clearResolvedInstances();
        Storyfeed::stories($stories);
        expect($manifest->apply(app(StoryfeedManager::class)))->toBeTrue()
            ->and(Storyfeed::glyphIntent('delivery', 'confirm'))->toBe('success');

        // An older manifest: the four original arrays, no glyphIntents.
        $older = array_diff_key($manifest->read(), ['glyphIntents' => true]);
        file_put_contents($manifest->path(), '<?php return '.var_export($older, true).';'.PHP_EOL);

        app()->forgetInstance(StoryfeedManager::class);
        Storyfeed::clearResolvedInstances();
        Storyfeed::stories($stories);
        expect($manifest->apply(app(StoryfeedManager::class)))->toBeTrue()
            ->and(Storyfeed::glyphIntent('delivery', 'confirm'))->toBeNull()
            ->and(Storyfeed::registeredGlyphIntents())->toBe([]);
    } finally {
        $manifest->delete();
    }
});

it('never reaches the AS2 document — the vocabulary has no term for it', function () {
    Storyfeed::grammar(['*.*' => ':actor did :object'])
        ->icons(['*.*' => 'bi-truck'])
        ->glyphIntents(['*.*' => 'success']);

    publishConfirm();

    $document = app(ActivitySerializer::class)->activity(Activity::query()->firstOrFail());
    $flat = json_encode($document);

    expect($flat)->not->toContain('glyph')
        ->and($flat)->not->toContain('intent')
        ->and($flat)->not->toContain('success');
});
