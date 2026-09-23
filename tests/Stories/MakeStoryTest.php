<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\StoryName;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

/*
 * The generator, which is the ONLY place inference happens — and it asks
 * rather than guesses, like Laravel's own generators.
 *
 * The one inference left is exact: a predicate that spells exactly one
 * declared verb. A suffix rule once printed 'complet' for TaskWasCompleted,
 * and the fix after it printed 'TODO'; both came from reading meaning out of a
 * name instead of asking. Nothing here writes a placeholder.
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
    expect(Artisan::call('make:story', [...$parameters, '--no-interaction' => true]))->toBe(0);

    return Artisan::output();
}

/**
 * Run make:story without a terminal, and return its failure message.
 *
 * @param  array<string, mixed>  $parameters
 */
function makeStoryFails(array $parameters): string
{
    expect(Artisan::call('make:story', [...$parameters, '--no-interaction' => true]))->toBe(1);

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
    $this->artisan('make:story', ['name' => 'DeliveryWasUploaded', '--no-interaction' => true])
        ->expectsOutputToContain("Bound to 'upload'")
        ->assertSuccessful();
});

it('names the declared verb a predicate spells, in every past-tense shape', function (string $class, string $verb) {
    Storyfeed::verbs(['complete' => 'Update', 'copy' => 'Create', 'ship' => 'Update', 'overdue' => 'Update', 'check_in' => 'Arrive', 'send' => 'Update']);

    expect(bindingIn(makeStory(['name' => $class])))->toEndWith("->verb('{$verb}', \\App\\Stories\\{$class}::class);");
})->with([
    '-d after e' => ['TaskWasCompleted', 'complete'],
    '-ied' => ['TaskWasCopied', 'copy'],
    'doubled consonant' => ['OrderWasShipped', 'ship'],
    'the verb itself' => ['TaskWasOverdue', 'overdue'],
    'two words' => ['GuestWasCheckedIn', 'check_in'],
    'irregular' => ['InvoiceWasSent', 'send'],
]);

it('fails without a terminal when no declared verb is spelled, naming the vocabulary', function (string $class) {
    // `uploaded → upload` and `completed → complete` are the same shape, so no
    // suffix rule can separate them. The old one printed 'complet'; the fix
    // after it printed 'TODO'. Neither reached a file that compiled right.
    Storyfeed::verbs(['upload' => 'Add', 'publish' => 'Update'], merge: false);

    $output = makeStoryFails(['name' => $class]);

    expect($output)->toContain("No declared verb is spelled by {$class}. Pass --verb; the declared verbs are 'upload', 'publish'.")
        ->and(storyPath($class))->not->toBeFile();
})->with([
    '-d after e' => ['TaskWasCompleted'],
    '-ied' => ['TaskWasCopied'],
    'doubled consonant' => ['OrderWasShipped'],
    'no past tense at all' => ['TaskWasOverdue'],
    'more than the verb' => ['TaskWasUploadedLate'],
]);

it('fails on two declared verbs a predicate spells, naming both, rather than pick', function () {
    // Free-form vocabularies store past tenses too. Both are real; choosing
    // would be a guess.
    Storyfeed::verbs(['complete' => 'Update', 'completed' => 'Update']);

    expect(makeStoryFails(['name' => 'TaskWasCompleted']))
        ->toContain("TaskWasCompleted spells more than one declared verb: 'complete', 'completed'. Pass --verb to choose.")
        ->and(storyPath('TaskWasCompleted'))->not->toBeFile();
});

it('reads only the predicate, since objects are nouns that are often verbs too', function () {
    Storyfeed::verbs(['comment' => 'Create', 'post' => 'Create']);

    expect(bindingIn(makeStory(['name' => 'CommentWasPosted'])))
        ->toBe("Story::for('comment')->verb('post', \\App\\Stories\\CommentWasPosted::class);");
});

it('splits on Was as a whole word only', function () {
    Storyfeed::verbs(['collect' => 'Add']);

    expect(bindingIn(makeStory(['name' => 'WasteWasCollected'])))
        ->toBe("Story::for('waste')->verb('collect', \\App\\Stories\\WasteWasCollected::class);");
});

it('takes --verb over any reading of the class name', function () {
    $output = makeStory(['name' => 'DishWentLive', '--verb' => 'publish', '--model' => 'MenuItem']);

    expect(bindingIn($output))->toEndWith("->verb('publish', \\App\\Stories\\DishWentLive::class);")
        ->and(file_get_contents(storyPath('DishWentLive')))->toContain(':actor published :object');
});

it('fails without a terminal when the name does not follow the convention', function () {
    expect(makeStoryFails(['name' => 'ConfirmDelivery', '--model' => Delivery::class]))
        ->toContain('ConfirmDelivery does not follow the {Object}Was{Verbed} convention, so the verb cannot be read from it. Pass --verb.');

    expect(makeStoryFails(['name' => 'ConfirmDelivery', '--verb' => 'confirm']))
        ->toContain('ConfirmDelivery does not name the model the story is about. Pass --model.');
});

describe('with a terminal, it asks', function () {
    it('offers the declared vocabulary when the name spells no verb', function () {
        Storyfeed::verbs(['upload' => 'Add', 'publish' => 'Update'], merge: false);

        $this->artisan('make:story', ['name' => 'DishWentLive', '--model' => 'Dish'])
            ->expectsChoice('Which verb does this story record?', 'publish', array_keys(Storyfeed::registeredVerbs()))
            ->expectsOutputToContain("Story::for('dish')->verb('publish', \\App\\Stories\\DishWentLive::class);")
            ->assertSuccessful();
    });

    it('asks between the verbs when the name spells two', function () {
        Storyfeed::verbs(['complete' => 'Update', 'completed' => 'Update'], merge: false);

        $this->artisan('make:story', ['name' => 'TaskWasCompleted'])
            ->expectsChoice('Which verb does this story record?', 'completed', array_keys(Storyfeed::registeredVerbs()))
            ->expectsOutputToContain("->verb('completed', ")
            ->assertSuccessful();
    });

    it('does not ask for what --verb, or the declared vocabulary, already settles', function () {
        Storyfeed::verbs(['complete' => 'Update']);

        // No expectsChoice: an unexpected prompt fails the test.
        $this->artisan('make:story', ['name' => 'TaskWasCompleted'])
            ->expectsOutputToContain("Bound to 'complete'")
            ->assertSuccessful();

        $this->artisan('make:story', ['name' => 'TaskWasDone', '--verb' => 'finish'])
            ->expectsOutputToContain("->verb('finish', ")
            ->assertSuccessful();
    });

    it('asks for free text when the app declares no verbs at all', function () {
        Storyfeed::verbs([], merge: false);

        $this->artisan('make:story', ['name' => 'DishWentLive', '--model' => 'MenuItem'])
            ->expectsQuestion('Which verb does this story record?', 'publish')
            ->expectsOutputToContain("->verb('publish', ")
            ->assertSuccessful();
    });

    it('asks for the model when the name does not carry one, suggesting the Feedable ones', function () {
        $this->artisan('make:story', ['name' => 'ConfirmDelivery', '--verb' => 'confirm'])
            ->expectsQuestion('Which model is the object of this story?', Delivery::class)
            ->expectsOutputToContain('Story::for(\\'.Delivery::class.'::class)->verb(\'confirm\', ')
            ->assertSuccessful();
    });

    it('asks for the name when none is given', function () {
        $this->artisan('make:story')
            ->expectsQuestion('What should the story be named?', 'DeliveryWasConfirmed')
            ->expectsOutputToContain("Story::for('delivery')->verb('confirm', ")
            ->assertSuccessful();
    });
});

it('never writes a placeholder into the class or the line', function () {
    $output = makeStory(['name' => 'DeliveryWasConfirmed']);

    expect($output)->not->toContain('TODO')
        ->and(file_get_contents(storyPath('DeliveryWasConfirmed')))->not->toContain('TODO');
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

it('refuses a bare invocation with no name and no terminal', function () {
    $this->artisan('make:story', ['--no-interaction' => true])
        ->expectsOutputToContain('--from-doctor')
        ->assertFailed();
});

describe('the verb rule', function () {
    it('generates spellings forward from the declared verb', function () {
        expect(StoryName::spellings('upload'))->toContain('uploaded')
            ->and(StoryName::spellings('create'))->toContain('created')
            ->and(StoryName::spellings('apply'))->toContain('applied')
            ->and(StoryName::spellings('submit'))->toContain('submitted')
            ->and(StoryName::spellings('play'))->toContain('played')
            ->and(StoryName::spellings('panic'))->toContain('panicked')
            ->and(StoryName::spellings('send'))->toContain('sent')
            ->and(StoryName::spellings('check_in'))->toContain('checkedin')
            ->and(StoryName::spellings('tentativeAccept'))->toContain('tentativeaccepted');
    });

    it('only ever answers with declared verbs', function () {
        // The stripping rule's failure: 'complet' was nobody's verb.
        expect(StoryName::verbsIn('TaskWasCompleted', []))->toBe([])
            ->and(StoryName::verbsIn('TaskWasCompleted', ['upload']))->toBe([])
            ->and(StoryName::verbsIn('TaskWasCompleted', ['complete', 'upload']))->toBe(['complete']);
    });

    it('conjugates for doctor only where the spelling is certain', function () {
        expect(StoryName::certainParticiple('confirm'))->toBe('confirmed')
            ->and(StoryName::certainParticiple('archive'))->toBe('archived')
            ->and(StoryName::certainParticiple('copy'))->toBe('copied')
            ->and(StoryName::certainParticiple('send'))->toBe('sent')
            ->and(StoryName::certainParticiple('set'))->toBe('set')
            // Doubling turns on stress: shipped, but visited.
            ->and(StoryName::certainParticiple('ship'))->toBeNull()
            ->and(StoryName::certainParticiple('visit'))->toBeNull()
            // Not one plain word, too short to judge, or already past.
            ->and(StoryName::certainParticiple('check_in'))->toBeNull()
            ->and(StoryName::certainParticiple('go'))->toBeNull()
            ->and(StoryName::certainParticiple('embed'))->toBeNull();
    });

    it('builds the participle back for --from-doctor names', function () {
        // Only the easy direction: appending is regular where stripping is not.
        expect(StoryName::participle('archive'))->toBe('archived')
            ->and(StoryName::participle('upload'))->toBe('uploaded')
            ->and(StoryName::participle('apply'))->toBe('applied')
            ->and(StoryName::participle('send'))->toBe('sent')
            ->and(StoryName::participle('play'))->toBe('played');
    });

    it('tolerates a Story suffix without turning it into the verb', function () {
        expect(StoryName::verbsIn('DocumentWasUploadedStory', ['upload']))->toBe(['upload']);
    });

    it('splits nothing when there is no delimiter', function () {
        expect(StoryName::parse('CreatePurchaseOrder'))
            ->toBe(['object' => null, 'predicate' => null]);
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
