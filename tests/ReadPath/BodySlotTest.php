<?php

use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\Prose;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Delivery;

/**
 * `body` — the slot a form goes in, on an entity.
 *
 * A form used to hide inside `data` at a key the app chose, so finding one
 * meant walking somebody else's map. The slot is core's and core still does
 * not read what is in it.
 */
beforeEach(function () {
    Delivery::$feedBody = null;
    Delivery::$mintsBody = null;
});

afterEach(function () {
    Delivery::$feedBody = null;
    Delivery::$mintsBody = null;
});

function body_of(string $role = 'object'): ?array
{
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    return app(NodePresenter::class)->activityNode($activity->fresh())[$role]['body'];
}

it('is null on an entity that has never written one', function () {
    expect(body_of())->toBeNull();
});

it('carries a form the model wrote, byte-identical', function () {
    Delivery::$feedBody = KeyValue::make(['Pickup' => '7:00 pm']);

    $body = body_of();

    expect($body)->toHaveCount(1)
        ->and($body[0])->toBe(KeyValue::make(['Pickup' => '7:00 pm'])->toPayload());
});

it('takes one form, several, or a line of text', function () {
    Delivery::$feedBody = 'Twelve services, no downtime.';
    expect(body_of()[0]['$body'])->toBe('Storyfeed/Body/Prose');

    Delivery::$feedBody = [Prose::make('a'), ItemList::make(['b'])];
    expect(array_column(body_of(), '$body'))
        ->toBe(['Storyfeed/Body/Prose', 'Storyfeed/Body/ItemList']);
});

it('puts what the snapshot stored before what the resolver minted', function () {
    Delivery::$feedBody = Prose::make('stored');
    Delivery::$mintsBody = fn () => ItemList::make(['minted']);

    expect(array_column(body_of(), '$body'))
        ->toBe(['Storyfeed/Body/Prose', 'Storyfeed/Body/ItemList']);
});

it('does not call a minted body that was handed over unbuilt until it is wanted', function () {
    $calls = 0;

    $media = FeedMedia::make('/x', body: function () use (&$calls) {
        $calls++;

        return Prose::make('late');
    });

    expect($calls)->toBe(0);

    $media->body();

    expect($calls)->toBe(1);
});

it('leaves data the app\'s own, and flattens a form nested in it', function () {
    $entity = FeedEntity::make('Label', data: ['ip' => '1.1.1.1', 'diff' => KeyValue::make(['A' => 'b'])]);

    expect($entity->data['ip'])->toBe('1.1.1.1')
        // The trap this closes: a nested Arrayable used to store `{}` unless
        // the app remembered ->toArray().
        ->and($entity->data['diff'])->toBe(KeyValue::make(['A' => 'b'])->toArray())
        ->and($entity->body)->toBe([]);
});
