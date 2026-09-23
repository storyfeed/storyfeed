<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedNoun;
use Workbench\App\Models\Delivery;

/*
 * Story::resource(): a model's lifecycle verbs in one line, the way
 * Route::resource() defines a controller's actions.
 */

it('defines create, update, delete and restore', function () {
    Story::resource(Delivery::class);

    expect(Storyfeed::template('delivery', 'create'))->toBe(':actor created :object')
        ->and(Storyfeed::template('delivery', 'update'))->toBe(':actor updated :object')
        ->and(Storyfeed::template('delivery', 'delete'))->toBe(':actor deleted :object')
        ->and(Storyfeed::template('delivery', 'restore'))->toBe(':actor restored :object')
        ->and(Storyfeed::actorlessTemplate('delivery', 'delete'))->toBe(':object was deleted')
        ->and(Storyfeed::icon('delivery', 'create'))->toBe('plus')
        ->and(Storyfeed::icon('delivery', 'restore'))->toBe('rotate-ccw')
        // The verbs keep their AS2.0 types from the shipped vocabulary.
        ->and(Storyfeed::activityTypeValue('delete'))->toBe('Delete');
});

it('narrows with only() and except(), after the call', function () {
    Story::resource(Delivery::class)->only(['create', 'update']);
    Story::resource('customer')->except('restore', 'delete');

    expect(Storyfeed::template('delivery', 'update'))->not->toBeNull()
        ->and(Storyfeed::registeredGrammar())->not->toHaveKey('delivery.delete')
        ->and(Storyfeed::registeredGrammar())->not->toHaveKey('delivery.restore')
        ->and(Storyfeed::registeredGrammar())->toHaveKeys(['customer.create', 'customer.update'])
        ->and(Storyfeed::registeredGrammar())->not->toHaveKey('customer.delete');
});

it('rejects a verb it does not define', function () {
    Story::resource(Delivery::class)->only('archive');
})->throws(InvalidArgumentException::class, 'Story::resource() has no [archive] verb');

it('registers the type noun, which is what a group of them reads through', function () {
    Story::resource(Delivery::class)->noun('delivery|deliveries');

    expect(Storyfeed::noun('delivery', 'create'))->toEqual(FeedNoun::of('delivery|deliveries'));
});

it('conflicts with a second definition of one of its verbs, naming both lines', function () {
    Story::resource(Delivery::class);
    Story::for(Delivery::class)->verb('update')->headline(':actor edited :object');

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'ResourceTest.php:');

it('lists its definitions with the line that made them', function () {
    Story::resource(Delivery::class)->only('create');

    Artisan::call('storyfeed:list', ['--json' => true]);

    $rows = json_decode(Artisan::output(), true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['headline'])->toBe(':actor created :object')
        ->and($rows[0]['source'])->toContain('ResourceTest.php:');
});
