<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\Noun;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The noun rung: rung 3 of the headline ladder (authored aggregate → safe
 * singular → SINGULAR WITH UNPINNED ROLES PLURALISED → verb label → count).
 *
 * Every assertion here is really about one sentence, so each test says what
 * the reader ends up looking at. The bar is not "the mechanism fired" — it
 * is that the sentence beats "Upload · 3 times", which reads as unfinished
 * and is therefore a perfectly good thing to fall back to.
 */
function sallyUploads(Customer $project, array $trackingNumbers): void
{
    $sally = User::firstOrCreate(
        ['email' => 'sally@example.com'],
        ['name' => 'Sally'],
    );

    foreach ($trackingNumbers as $tracking) {
        $delivery = Delivery::firstOrCreate(['tracking_number' => $tracking]);

        Storyfeed::activity()
            ->actor($sally)
            ->verb('upload', $delivery)
            ->for($project)
            ->publish();
    }
}

/** Keep the object axis out of the way so `repeat` wins on repeated objects. */
function onlyRepeatGroups(): void
{
    config()->set('storyfeed.grouping.policy.min_object_members', 99);
}

beforeEach(function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object']);
    Storyfeed::nouns(['delivery' => 'file|files']);
});

it('pluralises an unpinned role instead of throwing the sentence away', function () {
    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx', 'c.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The repeat axis pins :actor (and the object's KIND) but not the object
    // itself. "Sally uploaded 3 files" — true of every member, no authoring.
    // Before the rung this whole sentence was discarded for one token.
    expect($item['axis'])->toBe('repeat')
        ->and($item['headline_template'])->toBe(':actor uploaded 3 files')
        ->and($item['headline'])->toBeNull();
});

it('leaves :actor a token so the renderer can still link it', function () {
    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // Core must not pre-render the sentence: a pinned role is a real entity
    // the renderer turns into a link, and pre-rendering destroys it. What
    // comes back is a HYBRID TEMPLATE, and it rides the existing channel.
    expect($item['headline_template'])->toStartWith(':actor ')
        ->and($item['headline'])->toBeNull()
        ->and($item)->not->toHaveKey('headline_parts');
});

it('counts DISTINCT objects, not member activities', function () {
    onlyRepeatGroups();

    $project = Customer::create(['name' => 'Concur']);

    // Five uploads across two files. "5 files" would assert into existence
    // three files that do not exist — the lie of number this ladder refuses.
    sallyUploads($project, ['a.docx', 'a.docx', 'a.docx', 'b.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['axis'])->toBe('repeat')
        ->and($item['count'])->toBe(5)
        ->and($item['distinct']['objects'])->toBe(2)
        ->and($item['headline_template'])->toBe(':actor uploaded 2 files')
        ->and($item['headline_template'])->not->toContain('5 files');
});

it('counts objects it cannot see, not just the ones nested in children', function () {
    config()->set('storyfeed.grouping.children_limit', 2);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx', 'c.docx', 'd.docx', 'e.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The in-page count is capped; the sentence must use the TRUE distinct
    // total. A floor is fine for an exemplar list that admits to "and N
    // others" — it is not fine for a number a sentence asserts outright.
    expect($item['children'])->toHaveCount(2)
        ->and($item['children_truncated'])->toBeTrue()
        ->and($item['headline_template'])->toBe(':actor uploaded 5 files')
        ->and($item['headline_template'])->not->toContain('2 files');
});

it('names a role shared by every member rather than saying "1 file"', function () {
    onlyRepeatGroups();

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'a.docx', 'a.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // One distinct object across three members: the token is LEFT ALONE, so
    // the renderer resolves it from `exemplars` and the reader gets "Sally
    // uploaded a.docx". "1 file" would be a regression, not a fallback.
    expect($item['distinct']['objects'])->toBe(1)
        ->and($item['headline_template'])->toBe(':actor uploaded :object')
        ->and($item['exemplars']['objects'])->toHaveCount(1);
});

it('falls to the verb label when the template names a role nothing carries', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object in :context']);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // Zero distinct contexts means the role is ABSENT, not plural. "0 items"
    // would paper over exactly what the `roles` doctor check is watching
    // for; the muted label reads as unfinished, which is the truth.
    expect($item['distinct']['contexts'])->toBe(0)
        ->and($item['headline_template'])->toBeNull();
});

it('refuses to pluralise a token that is not a role', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object on :day']);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // Nothing can be counted, so nothing is claimed — and an invented token
    // left in the string would render as itself.
    expect($item['headline_template'])->toBeNull();
});

it('refuses a noun that could be read back as a token', function () {
    Storyfeed::nouns(['delivery' => 'clause :object|clauses :objects']);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The renderer tokenises the string we hand it, so a noun containing a
    // colon-word would be substituted a second time. Refuse rather than
    // mangle the author's words.
    expect($item['headline_template'])->toBeNull();
});

it('renders a generic noun rather than skipping the rung', function () {
    Storyfeed::nouns([], merge: false);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx', 'c.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // "Sally uploaded 3 items" is bland, and bland is still a sentence. The
    // screen belongs to the reader; the nagging belongs on the terminal.
    expect($item['headline_template'])->toBe(':actor uploaded 3 items');
});

it('does not eat the plural token that shares a prefix', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object of :objects']);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // `:objects` is universal — legal on every axis — so it must survive
    // the substitution of `:object` intact.
    expect($item['headline_template'])->toBe(':actor uploaded 2 files of :objects');
});

it('accepts a translated noun through the wrapper', function () {
    app('translator')->addLines(['nouns.delivery' => 'file|files'], 'en');

    Storyfeed::nouns(['delivery' => Noun::trans('nouns.delivery')]);

    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx']);

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['headline_template'])
        ->toBe(':actor uploaded 2 files');
});

it('still suppresses a fallback whose role KIND the axis does not pin', function () {
    Storyfeed::nouns(['user' => 'person|people']);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::firstOrCreate(['email' => strtolower($name).'@example.com'], ['name' => $name]);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "{$name}.docx"]))
            ->for($project)
            ->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The actors axis pins neither the actor's identity NOR its kind — an
    // actor can be a user or a Party — so "3 people uploaded 3 files" is a
    // lie of kind waiting to happen, and it is worse English than the label
    // it would replace. This group wants an AUTHORED template naming
    // :actors, and should keep reading as unfinished until it gets one.
    expect($item['axis'])->toBe('actors')
        ->and($item['headline_template'])->toBeNull();
});

it('will not assert a number over a slice that carries no true counts', function () {
    $project = Customer::create(['name' => 'Concur']);

    sallyUploads($project, ['a.docx', 'b.docx', 'c.docx']);

    $members = Activity::query()
        ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
        ->get();

    // A slice built without the aggregate counts — by a custom caller, or a
    // future read model — can only see the members it loaded, and that list
    // is capped. Counting them would understate the truth, so the rung
    // declines rather than assert a number it cannot stand behind.
    $node = app(NodePresenter::class)->groupNode(
        GroupSlice::group('repeat', 'h', 9, $members),
    );

    expect($node['headline_template'])->toBeNull();

    // The SAME slice, told the truth, does produce the sentence — so it is
    // the missing counts that stopped it, not the hand-built slice.
    $told = app(NodePresenter::class)->groupNode(
        GroupSlice::group('repeat', 'h', 9, $members, ['object' => 7]),
    );

    expect($told['headline_template'])->toBe(':actor uploaded 7 files');
});
