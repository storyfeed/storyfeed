<?php

use Storyfeed\Diagnostics\Checks\RemovalVerbs;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Tests\Fixtures\Guessed\Plate;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The doctor's two tombstone findings: removal-looking verbs the tombstone
 * rules treat as being about their object, and models on a guessed label.
 */

it('names a recorded verb that reads like a removal but is not classified', function () {
    $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    foreach (['cancel', 'void_payment', 'archive', 'delete', 'confirm', 'avoid'] as $verb) {
        Storyfeed::activity()->actor($ines)->verb($verb, $delivery)->publish();
    }

    $findings = Storyfeed::doctor(['removals'])->withCode('removals.unclassified');

    expect($findings->pluck('subject.verb')->all())->toBe(['cancel', 'void_payment'])
        ->and($findings->first()->severity)->toBe(Severity::Info)
        ->and($findings->first()->subject['types'])->toBe('delivery')
        ->and($findings->first()->message)->toContain("Story::verb('cancel')->missing()");
});

it('is silenced by a ->missing() declaration either way', function () {
    $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Story::verb('cancel')->missing('object');
    Story::for(Delivery::class)->verb('void_payment')->missing();

    Storyfeed::activity()->actor($ines)->verb('cancel', $delivery)->publish();
    Storyfeed::activity()->actor($ines)->verb('void_payment', $delivery)->publish();

    expect(Storyfeed::doctor(['removals'])->has('removals.unclassified'))->toBeFalse();
});

it('reads removal-looking verbs by the start of a word', function (string $verb, bool $looks) {
    expect(RemovalVerbs::looksLikeRemoval($verb))->toBe($looks);
})->with([
    ['delete', true], ['deleted', true], ['cancelled', true], ['order.remove', true], ['soft_delete', true],
    ['withdraw', true], ['unpublish', true], ['avoid', false], ['place', false], ['undelete', false],
]);

it('lists the models labelled by guesswork', function () {
    config()->set('storyfeed.discovery.paths', [dirname(__DIR__).'/Fixtures/Guessed']);
    Storyfeed::feedable(Photo::class);

    $finding = Storyfeed::doctor(['labels'])->withCode('labels.guessed')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject['models'])->toBe(implode(', ', [Plate::class, Photo::class]))
        ->and($finding->message)->toContain('2 Feedable models are labelled by guesswork');
});

it('says nothing when every model describes itself', function () {
    Storyfeed::feedable(Photo::class)->toFeedUsing(fn (Photo $photo, FeedEntity $entity) => $entity->label($photo->file_name));

    expect(Storyfeed::doctor(['labels'])->has('labels.guessed'))->toBeFalse();
});
