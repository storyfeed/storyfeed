<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\CompileStories;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Tests\Fixtures\Stories\RefundStory;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

function packageOverrideDefaults(): Verb
{
    return Verb::for('delivery', 'ship', 'package.php:10')->scopedToType()
        ->headline(':actor shipped :object')->anonymousHeadline(':object was shipped')
        ->icon('truck')->intent('success')->noun('delivery|deliveries')
        ->casts(['amount' => 'decimal:2', 'quantity' => 'integer'])
        ->whereActor('user')->whereObject('delivery')
        ->middleware('audit')->missing('object', 'target')->keepLatest(per: ['object'])
        ->onConnection('redis')->onQueue('package')->delay(5)->afterCommit()
        ->forgetWhenMissing()->keepFor('30 days')->groupedWeekly()
        ->name('package.ship')
        ->groups(
            Group::repeat()->headline(':actor shipped :count deliveries'),
            Group::byObject()->headline(':actor shipped :object :count times'),
        );
}

it('overrides only supplied fields regardless of registration order', function (bool $reverse) {
    $package = packageOverrideDefaults();
    $app = Verb::for('delivery', 'ship', 'app.php:20')->override()->headline(':actor sent :object');
    defineStories(...($reverse ? [$app, $package] : [$package, $app]));

    $compiled = Storyfeed::compiledStories();
    $defaults = (new CompileStories)([$package], app(StoryfeedManager::class));
    $defaults['grammar']['delivery.ship'] = ':actor sent :object';

    expect($compiled)->toBe($defaults)
        ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor sent :object')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('truck');
})->with(['package first' => false, 'app first' => true]);

it('merges supplied cast keys constraint roles and queue options', function (bool $reverse) {
    $package = packageOverrideDefaults();
    $app = Verb::for('delivery', 'ship', 'app.php:20')->override()
        ->casts(['quantity' => 'float', 'reference' => 'string'])
        ->whereActor('user', 'storyfeed.party')->onQueue('app')->beforeCommit()->delay(0)
        ->forgetWhenMissing(false);
    $second = Verb::for('delivery', 'ship', 'other.php:30')->override()
        ->casts(['note' => 'string'])->whereTarget('customer')->deleteWhenMissingModels(false);
    defineStories(...($reverse ? [$second, $app, $package] : [$package, $app, $second]));

    $compiled = Storyfeed::compiledStories();
    expect($compiled['casts']['delivery.ship'])->toBe([
        'amount' => 'decimal:2', 'quantity' => 'float', 'reference' => 'string', 'note' => 'string',
    ])->and($compiled['wheres']['delivery.ship'])->toBe([
        'actor' => ['user', 'storyfeed.party'], 'object' => ['delivery'], 'target' => ['customer'],
    ])->and($compiled['queue']['delivery.ship'])->toBe([
        'connection' => 'redis', 'queue' => 'app', 'delay' => 0, 'afterCommit' => false, 'deleteWhenMissingModels' => false,
    ])->and($compiled['forget']['delivery.ship'])->toBeFalse()
        ->and($compiled['middleware']['delivery.ship'])->toBe(['middleware' => ['audit'], 'excluded' => []]);
})->with(['package first' => false, 'app first' => true]);

it('replaces only the supplied group axis and composes disjoint overrides', function () {
    defineStories(
        packageOverrideDefaults(),
        Verb::for('delivery', 'ship', 'app.php:20')->scopedToType()->override()
            ->groups(Group::repeat()->headline(':actor sent :count deliveries')),
        Verb::for('delivery', 'ship', 'other.php:30')->override()->icon('send'),
    );

    $compiled = Storyfeed::compiledStories();
    expect($compiled['aggregateGrammar'])->toBe([
        'repeat.delivery.ship' => ':actor sent :count deliveries',
        'object.delivery.ship' => ':actor shipped :object :count times',
    ])->and($compiled['icons']['delivery.ship'])->toBe('send')
        ->and($compiled['grammar']['delivery.ship'])->toBe(':actor shipped :object');
});

it('replaces supplied whole properties without replacing unrelated package fields', function () {
    defineStories(
        packageOverrideDefaults(),
        Verb::for('delivery', 'ship', 'app.php:20')->override()
            ->middleware('app-audit')->withoutMiddleware('batch')->missing('object')
            ->keepLatest(per: ['actor', 'object'], within: '2 hours')
            ->anonymousHeadline(':object was sent')->noun('parcel|parcels')
            ->intent('info')->keepForever()->groupedDaily()->actor('Application'),
    );
    $compiled = Storyfeed::compiledStories();
    expect($compiled['middleware']['delivery.ship'])->toBe(['middleware' => ['app-audit'], 'excluded' => ['batch']])
        ->and($compiled['missing']['delivery.ship'])->toBe(['object'])
        ->and($compiled['keepLatest']['delivery.ship'])->toBe(['per' => ['actor', 'object'], 'within' => 'PT2H'])
        ->and($compiled['actorlessGrammar']['delivery.ship'])->toBe(':object was sent')
        ->and($compiled['nouns']['delivery.ship'])->toBe('parcel|parcels')
        ->and($compiled['glyphIntents']['delivery.ship'])->toBe('info')
        ->and($compiled['retention']['delivery.ship'])->toBe('forever')
        ->and($compiled['periods']['delivery.ship'])->toBe('day')
        ->and($compiled['actors']['delivery.ship'])->toBe('Application')
        ->and($compiled['icons']['delivery.ship'])->toBe('truck')
        ->and($compiled['casts']['delivery.ship']['amount'])->toBe('decimal:2');
});

it('does not hide ordinary conflicts behind an explicit override', function (bool $reverse) {
    $definitions = [
        Verb::for('delivery', 'ship', 'package.php:10')->headline('Package'),
        Verb::for('delivery', 'ship', 'accident.php:15')->headline('Accident'),
        Verb::for('delivery', 'ship', 'app.php:20')->override()->headline('App'),
    ];
    defineStories(...($reverse ? array_reverse($definitions) : $definitions));

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'package.php:10');
})->with([false, true]);

it('rejects distinct explicit owners of the same supplied slot', function (string $slot, bool $reverse) {
    $author = fn (string $source) => match ($slot) {
        'headline' => Verb::for('delivery', 'ship', $source)->override()->headline('App'),
        'cast' => Verb::for('delivery', 'ship', $source)->override()->casts(['quantity' => 'float']),
        'role' => Verb::for('delivery', 'ship', $source)->override()->whereActor('user'),
        'queue' => Verb::for('delivery', 'ship', $source)->override()->onQueue('app'),
        'middleware' => Verb::for('delivery', 'ship', $source)->override()->middleware('audit'),
        'group' => Verb::for('delivery', 'ship', $source)->scopedToType()->override()
            ->groups(Group::repeat()->headline(':actor sent :count deliveries')),
        'verb type' => Verb::for($source === 'first.php:10' ? 'delivery' : 'customer', 'ship', $source)->override()->type('Update'),
        'object type' => Verb::for('delivery', $source === 'first.php:10' ? 'ship' : 'hold', $source)->override()->activityStreamsType('Document'),
    };
    $first = $author('first.php:10');
    $second = $author('second.php:20');
    defineStories(...($reverse ? [$second, $first] : [$first, $second]));

    try {
        Storyfeed::compiledStories();
        test()->fail('Distinct explicit owners must conflict, even when the values match.');
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())->toContain('first.php:10', 'second.php:20');
    }
})->with(['headline', 'cast', 'role', 'queue', 'middleware', 'group', 'verb type', 'object type'])->with([false, true]);

it('retains the same-source duplicate convention', function () {
    defineStories(
        packageOverrideDefaults(),
        Verb::for('delivery', 'ship', 'app.php:20')->override()->headline('First')->casts(['quantity' => 'float']),
        Verb::for('delivery', 'ship', 'app.php:20')->override()->headline('Second')->casts(['quantity' => 'integer']),
    );

    expect(Storyfeed::template('delivery', 'ship'))->toBe('Second')
        ->and(Storyfeed::compiledStories()['casts']['delivery.ship']['quantity'])->toBe('integer');
});

it('makes marker-only and headline-less group overrides inert', function () {
    $package = packageOverrideDefaults();
    defineStories($package, Verb::for('delivery', 'ship', 'app.php:20')->override()->groups(Group::repeat()));

    expect(Storyfeed::compiledStories())->toBe((new CompileStories)([$package], app(StoryfeedManager::class)));
});

it('retains wildcard specificity and ordinary global verb type conventions', function () {
    defineStories(
        Verb::for('delivery', 'ship', 'package.php:10')->headline('Specific')->type('Create'),
        Verb::for('*', 'ship', 'package.php:11')->headline('Broad')->type('Update'),
        Verb::for('*', 'ship', 'app.php:20')->override()->headline('Broad override'),
    );

    expect(Storyfeed::template('delivery', 'ship'))->toBe('Specific')
        ->and(Storyfeed::template('customer', 'ship'))->toBe('Broad override')
        ->and(Storyfeed::compiledStories()['verbs']['ship'])->toBe('Update');
});

it('overrides the supplied global AS2 verb type without erasing an unsupplied one', function () {
    defineStories(
        Verb::for('delivery', 'ship', 'package.php:10')->type('Create'),
        Verb::for('customer', 'ship', 'app.php:20')->override()->type('Update'),
        Verb::for('delivery', 'ship', 'app.php:21')->override()->icon('send'),
    );

    expect(Storyfeed::compiledStories()['verbs']['ship'])->toBe('Update');
});

it('retains package action fingerprints and request actor under a headline override', function () {
    config()->set('storyfeed.grammar.strict', true);
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    $original = Storyfeed::compiledStories()['actions']['delivery.refund'];
    Story::for(Delivery::class)->verb('refund')->override()->headline(':actor reimbursed :object');
    app()->instance('request', Request::create('/refund', 'POST', ['provider' => 'Stripe']));
    $activity = Storyfeed::activity('refund', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(Storyfeed::compiledStories()['actions']['delivery.refund'])->toBe($original)
        ->and($activity->actor->name)->toBe('Stripe')
        ->and(Storyfeed::feed()->get()->toArray()[0]['headline_template'])->toBe(':actor reimbursed :object');
});

it('retains message bindings and resolved overlays through a fake swap', function () {
    Story::verb('confirm', DeliveryWasConfirmed::class);
    Story::for(Delivery::class)->verb('confirm')->override()->headline(':actor accepted :object');
    Storyfeed::fake();
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    Storyfeed::publish(new DeliveryWasConfirmed(Delivery::create(['tracking_number' => 'TN-1']), $user));

    Storyfeed::assertPublished('confirm');
    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor accepted :object')
        ->and(Storyfeed::icon('delivery', 'confirm'))->toBe('bi-truck')
        ->and(Storyfeed::storyVerb(DeliveryWasConfirmed::class))->toBe('confirm');
});

it('recompiles late overrides and late package defaults without stale compiled entries', function () {
    defineStories(packageOverrideDefaults());
    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object');
    defineStories(Verb::for('delivery', 'ship', 'app.php:20')->override()->headline('App'));
    expect(Storyfeed::template('delivery', 'ship'))->toBe('App');
    defineStories(Verb::for('delivery', 'ship', 'package.php:11')->missingHeadline('Removed'));
    expect(Storyfeed::template('delivery', 'ship'))->toBe('App')
        ->and(Storyfeed::compiledStories()['missingGrammar']['delivery.ship'])->toBe('Removed');
});

it('retains package names and current cache uniqueness rules', function () {
    defineStories(packageOverrideDefaults(), Verb::for('delivery', 'ship', 'app.php:20')->override()->headline('App'));
    expect(Storyfeed::compiledStories()['names'])->toBe(['package.ship' => 'delivery.ship']);
    (new CompileStories)->assertNamesCacheable(Storyfeed::storyDefinitions());
    defineStories(Verb::for('delivery', 'ship', 'app.php:21')->override()->name('app.ship'));
    expect(fn () => (new CompileStories)->assertNamesCacheable(Storyfeed::storyDefinitions()))
        ->toThrow(StoryMisconfigured::class, 'already named');
});

it('claims composite parent headlines independently of group headlines', function () {
    defineStories(
        Verb::for('*', 'ship', 'package.php:10')->groups(Group::composite()->headline(':count deliveries')->parentHeadline('Package parent')),
        Verb::for('*', 'ship', 'app.php:20')->override()->groups(Group::composite()->parentHeadline('App parent')),
    );
    expect(Storyfeed::template(null, 'ship'))->toBe('App parent')
        ->and(Storyfeed::compiledStories()['aggregateGrammar']['composite.ship'])->toBe(':count deliveries');

    defineStories(Verb::for('*', 'ship', 'app.php:21')->override()->headline('Competing parent'));
    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'app.php:20');
});

it('lists authored overrides with an additive marker and unchanged source rows', function () {
    Story::for('delivery')->verb('ship')->headline('Package')->icon('truck');
    Story::for('delivery')->verb('ship')->headline('App')->override();
    expect(Artisan::call('storyfeed:list', ['--json' => true]))->toBe(0);
    $rows = json_decode(Artisan::output(), true);
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'override'))->toBe([false, true])
        ->and(array_column($rows, 'headline'))->toBe(['Package', 'App'])
        ->and($rows[0]['icon'])->toBe('truck')
        ->and($rows[1]['icon'])->toBeNull()
        ->and($rows[0]['source'])->toContain('ExplicitOverridesTest.php:')
        ->and($rows[1]['source'])->toContain('ExplicitOverridesTest.php:');
    Artisan::call('storyfeed:list');
    expect(Artisan::output())->toContain('Override', 'yes');
});
