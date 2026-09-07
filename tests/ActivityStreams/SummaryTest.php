<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * AS2's `summary` on the activity document (docs/activity-streams.md,
 * `summary`): the grammar's sentence, labels substituted server-side, in the
 * language the author wrote it, encoded as HTML. Absent — never partial —
 * whenever a token cannot be filled.
 */
function confirmed_delivery(): Activity
{
    $user = User::create(['name' => 'Sally Nguyen', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1042']);

    return Storyfeed::activity('confirm', $delivery)->actor($user)->for($customer)->publish();
}

it('emits the grammar sentence as summary, every token replaced by its label', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update]);
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object for :target']);

    $document = serialize_one(confirmed_delivery());

    expect($document['summary'])->toBe('Sally Nguyen confirmed Delivery #TN-1042 for Acme Co.')
        // The guard: a summary reads as prose to a peer, so no token may
        // survive into it. Leave one unsubstituted in the serializer and
        // this line goes red.
        ->and($document['summary'])->not->toMatch('/:[a-z]+/');
});

it('withholds summary rather than emit a token the row cannot fill', function () {
    // `:context` names a role this activity does not carry.
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object in :context']);

    $document = serialize_one(confirmed_delivery());

    expect($document)->not->toHaveKey('summary');
});

it('withholds summary for an anonymous actor named by the template', function () {
    Storyfeed::grammar(['*.*' => ':actor did :object']);

    // No actor and no authenticated user: the actor is genuinely unknown,
    // and "Someone" is a renderer's word in a renderer's locale, not ours.
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(serialize_one($activity))->not->toHaveKey('summary');
});

it('withholds summary while a named entity is un-snapshotted', function () {
    Storyfeed::grammar(['*.*' => 'somebody confirmed :object']);

    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    expect(serialize_one($activity))->not->toHaveKey('summary');
});

it('withholds summary for a plural or invented token in a singular template', function (string $template) {
    Storyfeed::grammar(['delivery.confirm' => $template]);

    expect(serialize_one(confirmed_delivery()))->not->toHaveKey('summary');
})->with([
    'plural role' => ':actors confirmed :object',
    'aggregate count' => ':actor confirmed :count deliveries',
    'invented' => ':actor :verb :object',
]);

it('emits nothing when no grammar entry resolves', function () {
    expect(serialize_one(confirmed_delivery()))->not->toHaveKey('summary');
});

it('emits a closure-rendered headline as summary, and withholds it when the closure throws', function () {
    Storyfeed::grammar(['delivery.confirm' => fn (Activity $activity) => "Delivery {$activity->object_id} confirmed"]);

    $activity = confirmed_delivery();

    expect(serialize_one($activity)['summary'])->toBe("Delivery {$activity->object_id} confirmed");

    Storyfeed::grammar(['delivery.confirm' => fn () => throw new RuntimeException('authoring bug')]);

    expect(serialize_one($activity))->not->toHaveKey('summary');
});

it('encodes the sentence as HTML, so a label cannot inject markup', function () {
    Storyfeed::grammar(['*.*' => ':actor confirmed :object & more']);

    $user = User::create(['name' => '<b>Sally</b>', 'email' => 'sally@example.com']);
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->actor($user)->publish();

    expect(serialize_one($activity)['summary'])
        ->toBe('&lt;b&gt;Sally&lt;/b&gt; confirmed Delivery #TN-1 &amp; more');
});

it('never rescans a substituted label for tokens', function () {
    Storyfeed::grammar(['*.*' => ':actor confirmed :object']);

    $user = User::create(['name' => 'Re:actor Ltd', 'email' => 're@example.com']);
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->actor($user)->publish();

    expect(serialize_one($activity)['summary'])->toBe('Re:actor Ltd confirmed Delivery #TN-1');
});
