<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * The doctor reads definitions for their lines: dead vocabulary names where
 * it was defined, and a pair defined but never recorded (while its verb is
 * recorded elsewhere) is a likely copy-paste slip.
 */

it('names the line that defined a dead verb', function () {
    Story::verb('ship')->headline(':actor shipped :object');

    $finding = Storyfeed::doctor(['verbs'])->withCode('verbs.dead')
        ->first(fn ($finding) => $finding->subject['verb'] === 'ship');

    expect($finding->message)->toContain('DefinitionFindingsTest.php:15)')
        ->and($finding->subject['source'])->toEndWith('DefinitionFindingsTest.php:15');
});

it('reports a defined pair never recorded while its verb is recorded on another type', function () {
    Story::for(Delivery::class)->verb('confirm')->headline(':actor confirmed :object');
    Story::for(Customer::class)->verb('confirm')->headline(':actor confirmed :object');

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $findings = Storyfeed::doctor(['verbs'])->withCode('grammar.unrecorded');

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->subject)->toMatchArray(['type' => 'customer', 'verb' => 'confirm'])
        ->and($findings->first()->message)->toContain('DefinitionFindingsTest.php:26')
        ->and($findings->first()->severity->value)->toBe('info');
});

it('says nothing of a pair whose verb is recorded nowhere, which verbs.dead covers', function () {
    Story::for(Customer::class)->verb('ship')->headline(':actor shipped :object');

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(Storyfeed::doctor(['verbs'])->withCode('grammar.unrecorded'))->toBeEmpty();
});
