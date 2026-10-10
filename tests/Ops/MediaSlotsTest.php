<?php

use Storyfeed\Body\Image;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\DeferredMedia;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedImage;
use Storyfeed\MediaSlot;
use Storyfeed\Models\Snapshot;

/*
 * #107: a body that names one of its model's feedMedia() pictures by slot,
 * on a model whose feedMedia() never sets it, draws no picture and says
 * nothing. Customer's feedMedia() sets a link only; Dish's sets an icon.
 */

/** @param  list<array<string, mixed>>  $body */
function snapshotWithBody(string $type, int $id, array $body): Snapshot
{
    return Snapshot::create(['model_type' => $type, 'model_id' => $id, 'label' => "{$type} {$id}", 'data' => ['id' => $id], 'body' => $body]);
}

it('reports a body showing a slot its model\'s feedMedia() never sets', function () {
    $snapshot = snapshotWithBody('customer', 1, [Image::make(MediaSlot::Icon)->toPayload()]);

    $finding = Storyfeed::doctor(['media'])->withCode('media.unset_slot')->sole();

    expect($finding->severity)->toBe(Severity::Error)
        ->and($finding->subject)->toMatchArray(['alias' => 'customer', 'slot' => 'icon', 'examples' => "snapshot #{$snapshot->id}"])
        ->and($finding->message)->toContain('draws its text and no picture');
});

it('is silent when feedMedia() sets the slot the body shows', function () {
    snapshotWithBody('dish', 1, [MediaObject::make(subject: 'Pho')->image(MediaSlot::Icon)->toPayload()]);

    expect(Storyfeed::doctor(['media'])->all())->toBeEmpty();
});

it('reports each slot on its own, built-in and custom alike', function () {
    snapshotWithBody('dish', 2, [
        Image::make(MediaSlot::Icon)->toPayload(),
        Image::make(DeferredMedia::slot('sparkline'))->toPayload(),
        // Image::make() with nothing named shows the preview slot.
        Image::make()->toPayload(),
    ]);

    $slots = Storyfeed::doctor(['media'])->withCode('media.unset_slot')->pluck('subject.slot')->sort()->values()->all();

    expect($slots)->toBe(['preview', 'slots.sparkline']);
});

it('ignores a body that stores its own picture or names no slot', function () {
    snapshotWithBody('customer', 2, [
        Image::make(FeedImage::make()->src('/own.png'))->toPayload(),
        Prose::make('No picture here')->toPayload(),
    ]);

    expect(Storyfeed::doctor(['media'])->all())->toBeEmpty();
});
