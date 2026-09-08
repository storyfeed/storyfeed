<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;

it('discovers distinct null actor verbs and supplies actorless registration stubs', function () {
    Storyfeed::activity('confirm')->publish();
    Storyfeed::activity('confirm')->publish();
    Storyfeed::activity('publish')->publish();
    $findings = Storyfeed::doctor(['actorless'])->all();
    expect($findings)->toHaveCount(2)
        ->and($findings->pluck('subject.verb')->all())->toEqualCanonicalizing(['confirm', 'publish']);
    $this->artisan('storyfeed:doctor', ['--only' => ['actorless'], '--stubs' => true])
        ->expectsOutputToContain('Storyfeed::actorlessGrammar([')
        ->doesntExpectOutputToContain(':actor')->assertSuccessful();
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed', 'publish' => fn () => 'Published']);
    expect(Storyfeed::doctor(['actorless'])->all())->toBeEmpty();
});

it('is quiet for empty history and known actors including parties', function () {
    expect(Storyfeed::doctor(['actorless'])->all())->toBeEmpty();
    Storyfeed::activity('confirm')->actor(Party::make('Warehouse'))->publish();
    expect(Storyfeed::doctor(['actorless'])->all())->toBeEmpty();
});

it('ignores deleted activities in actorless coverage', function () {
    Storyfeed::activity('confirm')->publish()->delete();
    expect(Storyfeed::doctor(['actorless'])->all())->toBeEmpty();
});
