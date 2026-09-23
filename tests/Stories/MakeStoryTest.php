<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\StoryName;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/*
 * The generator, which is the ONLY place inference happens.
 *
 * The whole safety argument rests on the result being printed in the binding
 * line: a wrong one is visible and editable, and nothing consults the class
 * name at runtime. The verb is named only when the declared vocabulary settles
 * it — a suffix rule once printed 'complet' for TaskWasCompleted.
 */

function storyPath(string $class): string
{
    return app()->path("Stories/{$class}.php");
}

beforeEach(function () {
    Storyfeed::verbs(ActivityVerb::class);
});

afterEach(function () {
    foreach (glob(app()->path('Stories/*.php')) ?: [] as $file) {
        unlink($file);
    }
});

/**
 * Run make:story and return its output.
 *
 * @param  array<string, mixed>  $parameters
 */
function makeStory(array $parameters): string
{
    expect(Artisan::call('make:story', $parameters))->toBe(0);

    return Artisan::output();
}

/** The binding line in make:story's output, e.g. `Story::for(…)->verb('x', …);`. */
function bindingIn(string $output): string
{
    preg_match('/^\s*(Story::(?:for|resource)\(.*\);)\s*$/m', $output, $match);

    return $match[1] ?? '';
}

it('reads the object and verb from the Was delimiter, and prints the binding line', function () {
    $this->artisan('make:story', ['name' => 'DocumentWasUploaded'])
        ->expectsOutputToContain('Bind it in routes/feed.php')
        ->expectsOutputToContain("Story::for('document')->verb('upload', \\App\\Stories\\DocumentWasUploaded::class);")
        ->assertSuccessful();

    $source = file_get_contents(storyPath('DocumentWasUploaded'));

    // The line names the verb and the type, so the class doesn't repeat them.
    expect($source)
        ->not->toContain('$verb')
        ->not->toContain('$objectType')
        // The participle the developer wrote, not a conjugation of the
        // imperative — 'create' + 'ed' would be 'createed'.
        ->toContain(':actor uploaded :object');
});

it('handles the multi-word objects that killed token guessing', function () {
    // `CreatePurchaseOrder` cannot be split — is the object PurchaseOrder, or
    // the verb CreatePurchase? The Was infix removes the ambiguity, which is
    // the entire reason the convention has one.
    Storyfeed::verbs(['create' => 'Create']);

    $this->artisan('make:story', ['name' => 'PurchaseOrderWasCreated'])
        ->expectsOutputToContain("Story::for('purchase_order')->verb('create', ")
        ->assertSuccessful();
});

it('takes the verb from the app vocabulary, and says so', function () {
    // 'uploaded' → candidates [upload, uploade, uploaded]. The declared enum
    // settles it.
    $this->artisan('make:story', ['name' => 'DeliveryWasUploaded'])
        ->expectsOutputToContain("Bound to 'upload'")
        ->assertSuccessful();
});

it('names the verb for every past-tense shape the vocabulary declares', function (string $class, string $verb) {
    Storyfeed::verbs(['complete' => 'Update', 'copy' => 'Create', 'ship' => 'Update']);

    expect(bindingIn(makeStory(['name' => $class])))->toBe("Story::for('task')->verb('{$verb}', \\App\\Stories\\{$class}::class);");
})->with([
    '-ed after e' => ['TaskWasCompleted', 'complete'],
    '-ied' => ['TaskWasCopied', 'copy'],
    'doubled consonant' => ['TaskWasShipped', 'ship'],
]);

it('leaves the verb TODO rather than guess one the vocabulary does not declare', function (string $class) {
    // `uploaded → upload` and `completed → complete` are the same shape, so no
    // suffix rule can separate them. Ranking the bare stem first printed
    // 'complet', which looks plausible and is stored verbatim.
    $output = makeStory(['name' => $class]);

    expect($output)->toContain("so the binding line says 'TODO'")
        ->and(bindingIn($output))->toContain("->verb('TODO', ")
        ->and($output)->not->toContain("'complet'");
})->with([
    '-ed after e' => ['TaskWasCompleted'],
    '-ied' => ['TaskWasCopied'],
    'doubled consonant' => ['TaskWasShipped'],
    'no past tense at all' => ['TaskWasOverdue'],
]);

it('takes --verb over any reading of the class name', function () {
    $output = makeStory(['name' => 'DishWentLive', '--verb' => 'publish', '--model' => 'MenuItem']);

    expect($output)->not->toContain('convention')
        ->and(bindingIn($output))->toEndWith("->verb('publish', \\App\\Stories\\DishWentLive::class);");
});

it('warns when the class name does not follow the convention', function () {
    $output = makeStory(['name' => 'ConfirmDelivery']);

    // Still generates, with the line marked so nothing is silently wrong.
    expect($output)->toContain('does not follow the {Object}Was{Verbed} convention')
        ->and(bindingIn($output))->toBe("Story::for(TODO::class)->verb('TODO', \\App\\Stories\\ConfirmDelivery::class);");
});

it('resolves a model class when one exists, for a rename-safe reference', function () {
    $this->artisan('make:story', ['name' => 'DeliveryWasConfirmed', '--object' => Delivery::class])
        ->expectsOutputToContain('Story::for(\\'.Delivery::class.'::class)')
        ->assertSuccessful();
});

it('pre-fills only the axes that apply, with only pinned tokens', function () {
    $this->artisan('make:story', ['name' => 'DeliveryWasConfirmed'])->assertSuccessful();

    $source = file_get_contents(storyPath('DeliveryWasConfirmed'));

    expect($source)
        ->toContain('Group::byActors()')
        ->toContain('Group::byTargets()')
        ->toContain('Group::repeat()');

    // The generated skeleton must not be able to suggest an unpinned token —
    // that is the documented lie class, generated. `repeat` pins :actor and
    // :target; a byActors line may not offer :actor.
    $actorsLine = collect(explode(PHP_EOL, $source))->first(fn ($l) => str_contains($l, 'byActors()'));

    expect($actorsLine)->not->toContain(':actor ')
        ->and($actorsLine)->toContain(':actors');
});

it('honours an explicit axis list', function () {
    $this->artisan('make:story', ['name' => 'DeliveryWasConfirmed', '--axes' => 'repeat'])
        ->assertSuccessful();

    $source = file_get_contents(storyPath('DeliveryWasConfirmed'));

    expect($source)->toContain('Group::repeat()')
        ->and($source)->not->toContain('Group::byActors()');
});

it('generates a story that compiles once the printed line binds it', function () {
    $binding = bindingIn(makeStory(['name' => 'DeliveryWasConfirmed', '--object' => Delivery::class]));

    require storyPath('DeliveryWasConfirmed');

    // The end-to-end claim: the class and the line it printed are valid, and
    // compile into the registries without any hand editing.
    eval('use Storyfeed\Facades\Story; '.$binding);

    expect(Storyfeed::template('delivery', 'confirm'))->toContain(':actor')
        ->and(Storyfeed::aggregateTemplate('repeat', 'confirm'))->not->toBeNull();
});

it('scaffolds one story per unauthored pair doctor actually found', function () {
    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $output = makeStory(['--from-doctor' => true]);

    // NOT inference: `delivery.archive` was actually recorded. Transcribing what
    // the system observed is doctor's job; guessing what it meant stays banned.
    expect(storyPath('DeliveryWasArchived'))->toBeFile()
        ->and(bindingIn($output))->toBe("Story::for('delivery')->verb('archive', \\App\\Stories\\DeliveryWasArchived::class);");
});

it('says so when there is nothing to scaffold', function () {
    $this->artisan('make:story', ['--from-doctor' => true])
        ->expectsOutputToContain('Nothing to scaffold')
        ->assertSuccessful();
});

it('refuses a bare invocation with no name', function () {
    $this->artisan('make:story')
        ->expectsOutputToContain('--from-doctor')
        ->assertFailed();
});

describe('participle candidates', function () {
    it('handles the regular and irregular shapes', function () {
        expect(StoryName::candidates('Uploaded'))->toContain('upload')
            ->and(StoryName::candidates('Created'))->toContain('create')
            ->and(StoryName::candidates('Applied'))->toContain('apply')
            ->and(StoryName::candidates('Submitted'))->toContain('submit')
            ->and(StoryName::candidates('Archived'))->toContain('archive')
            ->and(StoryName::candidates('Sent'))->toBe(['send'])
            ->and(StoryName::candidates('Written'))->toBe(['write']);
    });

    it('builds the participle back for --from-doctor names', function () {
        // Only the easy direction: appending is regular where stripping is not.
        expect(StoryName::participle('archive'))->toBe('archived')
            ->and(StoryName::participle('upload'))->toBe('uploaded')
            ->and(StoryName::participle('apply'))->toBe('applied')
            ->and(StoryName::participle('send'))->toBe('sent');
    });

    it('tolerates a Story suffix without turning it into the verb', function () {
        expect(StoryName::parse('DocumentWasUploadedStory', ['upload'])['verb'])->toBe('upload');
    });

    it('names no verb the vocabulary does not settle', function () {
        expect(StoryName::parse('TaskWasCompleted'))->toBe(['object' => 'Task', 'verb' => null])
            ->and(StoryName::parse('TaskWasCompleted', ['complete']))->toBe(['object' => 'Task', 'verb' => 'complete']);
    });

    it('reports failure rather than guessing when there is no delimiter', function () {
        expect(StoryName::parse('CreatePurchaseOrder'))
            ->toBe(['object' => null, 'verb' => null]);
    });
});

it('writes a resource Story class, and prints the binding instead of editing the file', function () {
    $this->artisan('make:story', ['name' => 'ParcelStory', '--resource' => true, '--model' => Delivery::class])
        ->expectsOutputToContain('Bind it in routes/feed.php')
        ->expectsOutputToContain('Story::resource(\\'.Delivery::class.'::class, \App\Stories\ParcelStory::class);')
        ->assertSuccessful();

    $source = file_get_contents(storyPath('ParcelStory'));

    expect($source)
        ->toMatch('/^class ParcelStory\R/m')
        ->not->toContain('extends')
        ->toContain('public function create(Verb $verb): Verb')
        ->toContain('public function restore(Verb $verb): Verb')
        ->and(file_exists(base_path('routes/feed.php')) ? file_get_contents(base_path('routes/feed.php')) : '')->not->toContain('ParcelStory');

    // What it writes compiles to the conventional defaults.
    require_once storyPath('ParcelStory');
    Story::resource(Delivery::class, 'App\Stories\ParcelStory');

    expect(Storyfeed::template('delivery', 'delete'))->toBe(':actor deleted :object')
        ->and(Storyfeed::actorlessTemplate('delivery', 'create'))->toBe(':object was created')
        ->and(Storyfeed::storyActions())->toHaveKey('delivery.restore', 'App\Stories\ParcelStory@restore');
});

it('guesses the model from a resource class name, in the printed line', function () {
    $this->artisan('make:story', ['name' => 'InvoiceStory', '--resource' => true])
        ->expectsOutputToContain('Story::resource(\App\Models\Invoice::class, \App\Stories\InvoiceStory::class);')
        ->assertSuccessful();
});
