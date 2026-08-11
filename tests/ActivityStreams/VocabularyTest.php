<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\Context;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\ObjectType;

/**
 * Terms present in the published AS2.0 context that Storyfeed deliberately
 * does not model. Each exclusion is a decision, not an oversight.
 */
const DELIBERATE_EXCLUSIONS = [
    // Addressing sentinel, not a type — arrives with 2.x addressing.
    'Public',
    // Relationship subtypes from the Expanded Vocabulary wiki, not the REC.
    'IsContact', 'IsFollowedBy', 'IsFollowing', 'IsMember',
    // Link type — deferred with tagging (2.x); see docs/activity-streams.md.
    'Mention',
];

function vocabularyTerms(): array
{
    $context = json_decode(file_get_contents(__DIR__.'/../Fixtures/activitystreams.jsonld'), true);

    $terms = array_filter(
        array_keys($context['@context']),
        fn (string $term) => ctype_upper($term[0]),
    );

    return array_values(array_diff($terms, DELIBERATE_EXCLUSIONS));
}

it('models every activity streams term, and invents none', function () {
    $ours = array_unique([
        ...array_column(ActivityType::cases(), 'value'),
        ...array_column(ObjectType::cases(), 'value'),
        ...array_column(CoreType::cases(), 'value'),
    ]);

    $spec = vocabularyTerms();

    sort($ours);
    sort($spec);

    expect($ours)->toBe($spec);
});

it('marks exactly the three intransitive activity types', function () {
    $intransitive = array_values(array_filter(
        ActivityType::cases(),
        fn (ActivityType $type) => $type->isIntransitive(),
    ));

    expect($intransitive)->toBe([
        ActivityType::Arrive,
        ActivityType::Travel,
        ActivityType::Question,
    ]);
});

it('encodes the four activity subtype relationships', function () {
    expect(ActivityType::TentativeAccept->parent())->toBe(ActivityType::Accept)
        ->and(ActivityType::TentativeReject->parent())->toBe(ActivityType::Reject)
        ->and(ActivityType::Invite->parent())->toBe(ActivityType::Offer)
        ->and(ActivityType::Block->parent())->toBe(ActivityType::Ignore)
        ->and(ActivityType::Create->parent())->toBeNull();

    expect(ActivityType::TentativeAccept->isA(ActivityType::Accept))->toBeTrue()
        ->and(ActivityType::Create->isA(ActivityType::Accept))->toBeFalse();
});

it('reports the core type for transitive and intransitive activities', function () {
    expect(ActivityType::Create->coreType())->toBe(CoreType::Activity)
        ->and(ActivityType::Travel->coreType())->toBe(CoreType::IntransitiveActivity);
});

it('marks exactly the five actor types', function () {
    $actors = array_map(
        fn (ObjectType $type) => $type->value,
        array_values(array_filter(ObjectType::cases(), fn (ObjectType $t) => $t->isActor())),
    );

    expect($actors)->toBe(['Application', 'Group', 'Organization', 'Person', 'Service']);
});

it('encodes document subtypes', function () {
    expect(ObjectType::Audio->parent())->toBe(ObjectType::Document)
        ->and(ObjectType::Image->parent())->toBe(ObjectType::Document)
        ->and(ObjectType::Page->parent())->toBe(ObjectType::Document)
        ->and(ObjectType::Note->parent())->toBeNull();
});

it('builds full IRIs', function () {
    expect(ActivityType::Create->iri())->toBe('https://www.w3.org/ns/activitystreams#Create')
        ->and(ObjectType::Person->iri())->toBe('https://www.w3.org/ns/activitystreams#Person')
        ->and(Context::AS2)->toBe('https://www.w3.org/ns/activitystreams');
});

it('parses every spelling seen in the wild', function () {
    expect(ActivityType::tryFromLoose('Create'))->toBe(ActivityType::Create)
        ->and(ActivityType::tryFromLoose('create'))->toBe(ActivityType::Create)
        ->and(ActivityType::tryFromLoose('as:Create'))->toBe(ActivityType::Create)
        ->and(ActivityType::tryFromLoose('https://www.w3.org/ns/activitystreams#Create'))->toBe(ActivityType::Create)
        ->and(ActivityType::tryFromLoose('  Create  '))->toBe(ActivityType::Create);
});

it('returns null for unknown terms rather than throwing', function () {
    expect(ActivityType::tryFromLoose('toot:Emoji'))->toBeNull()
        ->and(ActivityType::tryFromLoose(''))->toBeNull()
        ->and(ObjectType::tryFromLoose('ext:Widget'))->toBeNull();
});

it('uses spec casing for the wire and lowercase for the stored verb', function () {
    expect(ActivityType::Create->value)->toBe('Create')
        ->and(ActivityType::Create->verb())->toBe('create')
        ->and(ActivityType::TentativeAccept->verb())->toBe('tentativeAccept')
        ->and(ActivityType::Create->activityType())->toBe(ActivityType::Create);
});
