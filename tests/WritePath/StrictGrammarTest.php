<?php

use Storyfeed\Exceptions\UnauthoredActivity;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * Strict grammar: the earliest place "the feed stopped keeping up with the app"
 * can be caught. GrammarCoverage catches it in CI and doctor catches it at
 * runtime, but both need someone to look; this fires where the publish call is
 * written.
 *
 * The suite disables it globally (see TestCase) because most of it exercises
 * the graceful-degradation guarantee deliberately, so these opt in.
 */

beforeEach(function () {
    config()->set('storyfeed.grammar.strict', true);
});

it('throws when publishing an activity nobody authored a headline for', function () {
    try {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
        $this->fail('Expected an UnauthoredActivity.');
    } catch (UnauthoredActivity $e) {
        expect($e->getMessage())
            ->toContain('delivery.confirm')
            ->toContain('blank line')
            // Names the fix, both ways.
            ->toContain("Storyfeed::grammar(['delivery.confirm'")
            ->toContain('Story')
            // And refuses to suggest the catch-all that would silence
            // every future gap.
            ->toContain('Resist `*.*`');
    }
});

it('is satisfied by a Story', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $activity = DeliveryWasConfirmed::publish(Delivery::create(['tracking_number' => 'TN-1']));

    expect($activity->exists)->toBeTrue();
});

it('is satisfied by a partial wildcard', function () {
    Storyfeed::grammar(['*.confirm' => ':actor confirmed something']);

    expect(
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish()->exists
    )->toBeTrue();
});

it('does not gate on the icon', function () {
    // A missing icon degrades to a wildcard, which is cosmetic. A missing
    // headline is a blank line. Only the second is worth stopping a publish.
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    expect(
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish()->exists
    )->toBeTrue();
});

it('never gates production', function () {
    config()->set('storyfeed.grammar.strict', false);

    // "Activities are never hidden by the read path" — an unauthored activity
    // must still publish and degrade to a null headline.
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect($activity->exists)->toBeTrue()
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['headline_template'])->toBeNull();
});
