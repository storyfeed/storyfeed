<?php

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryDefinition;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\GrammarCoverage;
use Storyfeed\Testing\StorySurface;
use Workbench\App\Models\Courier;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The inventory: what publishes, and what could but doesn't.
 *
 * This is the answer to "a developer joining in six months cannot discover what
 * the feed publishes or from where" — discoverability as a TOOL rather than a
 * filing convention, so call sites stay wherever they belong.
 */

it('names the story class as the source of what it publishes', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)
        ->publish();

    // The whole point for a developer joining in six months: the inventory
    // says WHERE the story is defined.
    $this->artisan('storyfeed:stories')
        ->expectsOutputToContain('DeliveryWasConfirmed')
        ->assertSuccessful();
});

it('reports pairs recorded from call sites the package never saw', function () {
    // The inventory must describe the APP, not only the part that adopted the
    // Story layer — otherwise it is a list of what already migrated.
    Storyfeed::grammar(['delivery.archive' => ':actor archived :object']);

    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Artisan::call('storyfeed:stories', ['--json' => true]);

    $rows = json_decode(Artisan::output(), true);

    $archive = collect($rows)->firstWhere('verb', 'archive');

    expect($archive)->not->toBeNull()
        // Named as a call site rather than omitted: a developer reading this
        // needs to know the publish exists even though no Story describes it.
        ->and($archive['source'])->toBe('(call site)')
        ->and($archive['grammar'])->toBeTrue();
});

it('marks a recorded pair with no grammar as unauthored', function () {
    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('unauthored')
        ->assertSuccessful();
});

it('marks an authored-but-never-recorded story as dead', function () {
    Storyfeed::stories([
        StoryDefinition::make('delivery.confirm')->headline(':actor confirmed :object'),
    ]);

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('dead')
        ->assertSuccessful();
});

it('marks a story missing an applicable axis as a gap', function () {
    Storyfeed::stories([
        // Authored singular, and only ONE of the axes `upload` can reach.
        StoryDefinition::make('delivery.upload')
            ->headline(':actor uploaded :object')
            ->groups(Group::repeat()->headline(':actor uploaded :count')),
    ]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    Storyfeed::activity()->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publish();

    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('gap')
        // Points at the command that prints the fix.
        ->expectsOutputToContain('storyfeed:doctor --stubs')
        ->assertSuccessful();
});

it('names a declared model that never appears, once there is data to judge against', function () {
    // With an EMPTY table every declared model trivially never appears, so the
    // check refuses a verdict rather than reporting the whole app as broken —
    // the defect its first consumer hit. Give it one activity and the answer
    // becomes real: Delivery appears, User and Customer do not.
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    // Order matters to the helper: each expectation consumes the first matching
    // line, and one table row contains both words.
    $this->artisan('storyfeed:stories')
        ->expectsOutputToContain('User')
        ->expectsOutputToContain('unwired')
        ->assertSuccessful();
});

it('reports every story as ok when there is nothing to flag', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // The fixture authors three of the four axes `confirm` can reach, so the
    // fourth has to be filled in for a clean run — which is itself the
    // derivation working: nobody hand-listed which axes apply.
    Storyfeed::aggregateGrammar(['object.confirm' => ':actor confirmed :object :count times']);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->context(Courier::create(['name' => 'Ada']))->publish();

    // And every Feedable model is now in the feed in SOME role — the actor and
    // target count, not just the object.
    $this->artisan('storyfeed:stories --gaps')
        ->expectsOutputToContain('Every story is ok')
        ->assertSuccessful();
});

it('warns when the newest activity is older than --since', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)
        ->publishedAt(now()->subDays(90))
        ->publish();

    $this->artisan('storyfeed:stories --since=30')
        ->expectsOutputToContain('may have stopped growing')
        ->assertSuccessful();
});

it('refuses a verdict when nothing has been published', function () {
    // THE defect its first consumer hit: against a RefreshDatabase suite every
    // declared model trivially never appears, so this failed wholesale while
    // knowing nothing — and the only way to green it was to except everything,
    // which is a permanently vacuous assertion. They deleted it, correctly.
    // Absence of evidence must be reported as absence of evidence.
    try {
        StorySurface::assertNoUnwiredSurface();
        $this->fail('Expected the assertion to refuse a verdict.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('proves nothing');
    }
});

it('passes once every declared model appears in SOME role', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    // `Feedable` means the model APPEARS in the feed, not that it publishes.
    // Publishing from an Action class while the model is merely a role is an
    // ordinary Laravel shape, and must satisfy this.
    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->context(Courier::create(['name' => 'Ada']))->publish();

    StorySurface::assertNoUnwiredSurface();
});

it('names a model that never appears, and does not conflate that with publishing', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    try {
        StorySurface::assertNoUnwiredSurface();
        $this->fail('Expected unwired surface to be reported.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('never appears in the feed')
            ->toContain('User')
            ->toContain('APPEARS in the feed')
            ->toContain('invisible to Storyfeed');
    }
});

it('works under Storyfeed::fake(), like its two sibling assertions', function () {
    // The gap the Newsroom hit: GrammarCoverage has been fake-aware from the
    // start, so reaching for all three coverage assertions in one faked test gave
    // two passes and one inexplicable refusal. A namespace where two of three
    // work under fake() is worse than one where none do — the inconsistency is
    // what sends you to the wrong conclusion about which tool is broken.
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->context(Courier::create(['name' => 'Ada']))->publish();

    // Nothing reached the table, and this still returns a real verdict.
    StorySurface::assertNoUnwiredSurface();
    GrammarCoverage::assertCoversRecorded();
});

it('still names unwired surface when faked', function () {
    Storyfeed::fake();
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    // Only Delivery appears — the fake must not be a blanket pass either.
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    try {
        StorySurface::assertNoUnwiredSurface();
        $this->fail('Expected unwired surface to be reported.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('User');
    }
});

it('accepts deliberately absent surface via $except', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    StorySurface::assertNoUnwiredSurface(except: [User::class, Customer::class, Courier::class]);
});

it('names the aliases it compared against, so a stale morph alias is visible without SQL', function () {
    /*
     * THE FINDING OFFERS A FORK AND USED TO WITHHOLD WHAT DECIDES IT — "either
     * something should be publishing about it and nothing does, or the contract
     * is left over from something removed". Resolving that needs the aliases
     * that ARE recorded, which this check already computed in order to say the
     * missing one is not among them.
     *
     * Withholding them sent a consumer to hand-roll a query against an internal
     * table name they had to go and read in vendor/. They got the name wrong —
     * `activities` rather than `feed_activities` — and spent a production
     * command finding out.
     *
     * The commonest cause is the one this makes obvious at a glance: a stored
     * alias that is not the alias the model reports today, because the rows
     * predate a morph-map entry.
     */
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $findings = [];

    foreach (app(StoryfeedManager::class)->doctor(['surface'])->withCode('surface.unwired') as $finding) {
        $findings[] = $finding;
    }

    expect($findings)->not->toBeEmpty();

    $finding = $findings[0];

    expect($finding->message)->toContain('Aliases that ARE recorded: delivery')
        // A reader who does not know the read path should not conclude from an
        // absence that nothing was ever recorded.
        ->toContain('Soft-deleted activities are not counted.')
        // The full set rides on the subject for anything reading it as data —
        // as a string, because a subject holds scalars.
        ->and($finding->subject['recorded'])->toContain('delivery');
});
