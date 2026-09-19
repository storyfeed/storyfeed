<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Verb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/**
 * The shipped verb vocabulary, as assertions.
 *
 * This enum is a public promise: consumers store its values, so a case that
 * changes spelling or mapping breaks rows already written. These tests pin
 * the properties the docblock claims, so the claims cannot quietly stop
 * being true.
 */
it('reaches every Activity Streams type, so it can be the only authoring surface', function () {
    /*
     * The precondition for ActivityType dropping `implements FeedVerb`: an
     * author must never have to fall back to the transcription to express
     * something. If this fails, removing it would strand a use case.
     */
    $reachable = array_unique(array_map(fn (Verb $v) => $v->activityType(), Verb::cases()), SORT_REGULAR);

    expect($reachable)->toHaveCount(count(ActivityType::cases()));
});

it('spells every verb in the present tense', function () {
    /*
     * Jasper, 2026-09-19: present tense always. A vocabulary mixing tenses
     * hands an app two strings for one fact and no error when it uses both.
     */
    foreach (Verb::cases() as $verb) {
        expect($verb->value)->not->toEndWith('ed', "'{$verb->value}' reads as past tense");
    }
});

it('stores a value that matches its case name', function () {
    foreach (Verb::cases() as $verb) {
        expect($verb->value)->toBe(lcfirst($verb->name));
    }
});

it('maps each verb to exactly one type, and never throws doing it', function () {
    /*
     * `activityType()` is a total match over cases. A case added without a
     * mapping raises UnhandledMatchError at runtime rather than at build,
     * which is the one way this enum could ship broken.
     */
    foreach (Verb::cases() as $verb) {
        expect($verb->activityType())->toBeInstanceOf(ActivityType::class);
    }
});

it('records with a shipped verb the same way an app enum does', function () {
    $actor = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $object = Customer::create(['name' => 'Order 1001']);

    $activity = Verb::Send->actor($actor)->object($object)->publish();

    expect($activity->verb)->toBe('send')
        ->and(Verb::Send->activityType())->toBe(ActivityType::Offer);
});
