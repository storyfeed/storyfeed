<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\StoryName;
use Workbench\App\Models\Delivery;

/*
 * The generator, which is the ONLY place inference happens.
 *
 * The whole safety argument rests on the guess being written into the file as a
 * literal: a wrong one shows up in the diff, and nothing consults the class name
 * at runtime. That is what makes it safe for the heuristic below to be
 * aggressive.
 */

function storyPath(string $class): string
{
    return app()->path("Stories/{$class}.php");
}

afterEach(function () {
    foreach (glob(app()->path('Stories/*.php')) ?: [] as $file) {
        unlink($file);
    }
});

it('reads the object and verb from the Was delimiter', function () {
    $this->artisan('make:story', ['name' => 'DocumentWasUploaded'])->assertSuccessful();

    $source = file_get_contents(storyPath('DocumentWasUploaded'));

    expect($source)
        ->toContain("\$verb = 'upload'")
        ->toContain("\$objectType = 'document'")
        // The participle the developer wrote, not a conjugation of the
        // imperative — 'create' + 'ed' would be 'createed'.
        ->toContain(':actor uploaded :object');
});

it('handles the multi-word objects that killed token guessing', function () {
    // `CreatePurchaseOrder` cannot be split — is the object PurchaseOrder, or
    // the verb CreatePurchase? The Was infix removes the ambiguity, which is
    // the entire reason the convention has one.
    $this->artisan('make:story', ['name' => 'PurchaseOrderWasCreated'])->assertSuccessful();

    expect(file_get_contents(storyPath('PurchaseOrderWasCreated')))
        ->toContain("\$verb = 'create'")
        ->toContain("\$objectType = 'purchase_order'");
});

it('prefers the app vocabulary over a suffix rule', function () {
    // 'uploaded' → candidates [upload, uploade]. The declared enum settles it,
    // and confidence is reported to the developer.
    $this->artisan('make:story', ['name' => 'DeliveryWasUploaded'])
        ->expectsOutputToContain("Wrote \$verb = 'upload'")
        ->assertSuccessful();
});

it('warns when the verb is a guess outside the declared vocabulary', function () {
    // `uploaded → upload` and `frobnicated → frobnicate` are structurally
    // identical, so no suffix rule can separate them — it needs a dictionary.
    // The design answer is not a cleverer rule but an honest warning, which is
    // only acceptable because this runs at generator time.
    $this->artisan('make:story', ['name' => 'DeliveryWasFrobnicated'])
        ->expectsOutputToContain('is a guess')
        ->assertSuccessful();

    $written = file_get_contents(storyPath('DeliveryWasFrobnicated'));

    expect(collect(StoryName::candidates('Frobnicated'))
        ->contains(fn (string $candidate) => str_contains($written, "\$verb = '{$candidate}'")))
        ->toBeTrue();
});

it('warns when the class name does not follow the convention', function () {
    $this->artisan('make:story', ['name' => 'ConfirmDelivery'])
        ->expectsOutputToContain('does not follow the {Object}Was{Verbed} convention')
        ->assertSuccessful();

    // Still generates, with the fields marked so nothing is silently wrong.
    expect(file_get_contents(storyPath('ConfirmDelivery')))->toContain('TODO');
});

it('resolves a model class when one exists, for a rename-safe reference', function () {
    $this->artisan('make:story', ['name' => 'DeliveryWasConfirmed', '--object' => Delivery::class])
        ->assertSuccessful();

    expect(file_get_contents(storyPath('DeliveryWasConfirmed')))
        ->toContain(Delivery::class.'::class');
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

it('generates a compilable, registerable story', function () {
    $this->artisan('make:story', ['name' => 'DeliveryWasConfirmed', '--object' => Delivery::class])
        ->assertSuccessful();

    require storyPath('DeliveryWasConfirmed');

    // The end-to-end claim: generated output is valid, and it compiles into the
    // registries without any hand editing.
    Storyfeed::stories(['App\Stories\DeliveryWasConfirmed']);

    expect(Storyfeed::template('delivery', 'confirm'))->toContain(':actor')
        ->and(Storyfeed::aggregateTemplate('repeat', 'confirm'))->not->toBeNull();
});

it('scaffolds one story per unauthored pair doctor actually found', function () {
    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('make:story', ['--from-doctor' => true])->assertSuccessful();

    // NOT inference: `delivery.archive` was actually recorded. Transcribing what
    // the system observed is doctor's job; guessing what it meant stays banned.
    expect(storyPath('DeliveryWasArchived'))->toBeFile()
        ->and(file_get_contents(storyPath('DeliveryWasArchived')))->toContain("\$verb = 'archive'");
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
        expect(StoryName::parse('DocumentWasUploadedStory')['verb'])->toBe('upload');
    });

    it('reports failure rather than guessing when there is no delimiter', function () {
        expect(StoryName::parse('CreatePurchaseOrder'))
            ->toBe(['object' => null, 'verb' => null, 'confident' => false]);
    });
});
