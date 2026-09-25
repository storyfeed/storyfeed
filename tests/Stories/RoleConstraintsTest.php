<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryRoleMismatch;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasSorted;
use Workbench\App\Models\Courier;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Role constraints, modelled on route constraints: `->whereActor()`,
 * `->whereRole('origin', …)` and the rest say which types a role may be.
 * A publish that breaks one throws, as a route whose `->where()` fails
 * doesn't match. Compared by morph alias; an empty role never violates.
 */

afterEach(function () {
    app(StoryManifest::class)->delete();
});

function aDelivery(): Delivery
{
    return Delivery::create(['tracking_number' => 'TN-'.str()->random(6)]);
}

function aUser(): User
{
    return User::create(['name' => 'Sally', 'email' => str()->random(8).'@example.com']);
}

describe('declaring', function () {
    it('resolves classes to morph aliases and keeps aliases as given', function () {
        Story::for(Delivery::class)->verb('ship')
            ->whereActor(User::class, 'party')
            ->whereTarget('customer')
            ->whereRole('origin', [Courier::class, Customer::class]);

        expect(Storyfeed::wheres('delivery', 'ship'))->toBe([
            'actor' => ['user', 'storyfeed.party'],
            'target' => ['customer'],
            'origin' => ['courier', 'customer'],
        ]);
    });

    it('takes the Party model as the party alias', function () {
        Story::verb('sync')->whereActor(Party::class);

        expect(Storyfeed::wheres(null, 'sync'))->toBe(['actor' => ['storyfeed.party']]);
    });

    it('replaces a role said again, as ->where() replaces a parameter\'s pattern', function () {
        Story::verb('sync')->whereActor(User::class)->whereActor(Courier::class);

        expect(Storyfeed::wheres(null, 'sync'))->toBe(['actor' => ['courier']]);
    });

    it('refuses what is not a role or not a type', function (Closure $declare, string $message) {
        expect($declare)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'an unknown role' => [fn () => Story::verb('sync')->whereRole('owner', User::class), 'names [owner], which is not a role'],
        'no types' => [fn () => Story::verb('sync')->whereActor(), 'was given no types for the actor'],
        'a wildcard' => [fn () => Story::verb('sync')->whereActor('*'), 'leave the actor unconstrained'],
        'a class that is not a model' => [fn () => Story::verb('sync')->whereTarget(DeliveryWasSorted::class), 'is not an Eloquent model'],
        'a missing class' => [fn () => Story::verb('sync')->whereTarget('App\\Models\\Nope'), 'is not an Eloquent model'],
    ]);

    it('puts a group\'s constraints beneath each verb\'s own, per role', function () {
        Story::whereActor(User::class)->whereTarget(Customer::class)->group(function () {
            Story::for(Delivery::class)->verb('ship')->whereActor(Courier::class);
            Story::whereTarget(Courier::class)->group(fn () => Story::verb('wave'));
        });

        expect(Storyfeed::wheres('delivery', 'ship'))->toBe(['actor' => ['courier'], 'target' => ['customer']])
            ->and(Storyfeed::wheres(null, 'wave'))->toBe(['actor' => ['user'], 'target' => ['courier']]);
    });

    it('takes them on a bound class, a resource, and the array form', function () {
        Story::for(Delivery::class)->verb('sort', DeliveryWasSorted::class)->whereActor('party');
        Story::resource(Customer::class)->only('create')->whereActor(User::class);
        Story::verb('sync')->fill(['where' => ['actor' => User::class, 'origin' => [Courier::class]]], 'a test');
        Story::resources([Courier::class => null], ['only' => ['create'], 'wheres' => ['actor' => 'user']]);

        expect(Storyfeed::wheres('delivery', 'sort'))->toBe(['actor' => ['storyfeed.party']])
            ->and(Storyfeed::wheres('customer', 'create'))->toBe(['actor' => ['user']])
            ->and(Storyfeed::wheres(null, 'sync'))->toBe(['actor' => ['user'], 'origin' => ['courier']])
            ->and(Storyfeed::wheres('courier', 'create'))->toBe(['actor' => ['user']]);
    });

    it('reaches a verb from its type\'s fallback, on the type → verb ladder', function () {
        Story::for(Delivery::class)->fallback()->whereActor(User::class);
        Story::for(Delivery::class)->verb('return')->whereActor(Courier::class);

        expect(Storyfeed::wheres('delivery', 'ship'))->toBe(['actor' => ['user']])
            ->and(Storyfeed::wheres('delivery', 'return'))->toBe(['actor' => ['courier']])
            ->and(Storyfeed::wheres('customer', 'ship'))->toBe([]);
    });
});

describe('publishing', function () {
    it('publishes a role of an allowed type', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

        $activity = Storyfeed::activity('ship', aDelivery())->actor(aUser())->publish();

        expect($activity->exists)->toBeTrue();
    });

    it('throws naming the verb, role, expected and given types', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

        expect(fn () => Storyfeed::activity('ship', aDelivery())->actor(Customer::create(['name' => 'Ada']))->publish())
            ->toThrow(StoryRoleMismatch::class, 'Wrong actor for [Verb: ship] [Key: delivery.ship] [Expected: user] [Given: customer].');

        expect(Activity::query()->count())->toBe(0);
    });

    it('treats a party as its own type, allowed only when named', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);
        Story::for(Delivery::class)->verb('sync')->whereActor(User::class, 'party');

        expect(fn () => Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish())
            ->toThrow(StoryRoleMismatch::class, "[Given: storyfeed.party]. The verb's ->whereActor() says which types may be its actor; add 'party'")
            ->and(Storyfeed::activity('sync', aDelivery())->actor('Stripe')->publish()->exists)->toBeTrue();
    });

    it('never counts an anonymous actor or an empty role as a violation', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class)->whereTarget(Customer::class);

        $activity = Storyfeed::activity('ship', aDelivery())->anonymously()->publish();

        expect($activity->exists)->toBeTrue()
            ->and($activity->actor_type)->toBeNull();
    });

    it('checks a role a scope filled', function () {
        Story::for(Delivery::class)->verb('ship')->whereContext(Customer::class);

        expect(fn () => Storyfeed::context(aDelivery(), fn () => Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish()))
            ->toThrow(StoryRoleMismatch::class, 'Wrong context for [Verb: ship]');
    });

    it('checks a role with no shorthand, and says how it was declared', function () {
        Story::verb('import')->whereRole('origin', Courier::class);

        expect(fn () => Storyfeed::activity('import')->actor('Stripe')->origin(aDelivery())->publish())
            ->toThrow(StoryRoleMismatch::class, "[Key: *.import] [Expected: courier] [Given: delivery]. The verb's ->whereRole('origin', …) says");
    });

    it('checks each member of a composite as the object', function () {
        Story::verb('upload')->whereObject(Delivery::class);

        expect(fn () => Storyfeed::activity('upload')->actor('Stripe')->objects([aDelivery(), Customer::create(['name' => 'Ada'])])->publish())
            ->toThrow(StoryRoleMismatch::class, 'Wrong object for [Verb: upload] [Key: *.upload] [Expected: delivery] [Given: customer]');
    });

    it('checks a message class\'s publish against its line', function () {
        Story::for(Delivery::class)->verb('sort', DeliveryWasSorted::class)->whereActor(User::class);

        expect(fn () => Storyfeed::publish(new DeliveryWasSorted(aDelivery())))
            ->toThrow(StoryRoleMismatch::class, 'Wrong actor for [Verb: sort]');
    });

    it('checks under the fake too, so a test agrees with production', function () {
        Storyfeed::fake();
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

        expect(fn () => Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish())
            ->toThrow(StoryRoleMismatch::class)
            ->and(fn () => Storyfeed::activity('ship', aDelivery())->actor('Stripe')->queue())
            ->toThrow(StoryRoleMismatch::class);
    });
});

describe('tooling', function () {
    it('lists them in storyfeed:list, in --json and with -v', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class, 'party');
        Story::for(Delivery::class)->verb('hold');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

        expect($rows['ship']['where'])->toBe(['actor: user, storyfeed.party'])
            ->and($rows['hold']['where'])->toBe([]);

        Artisan::call('storyfeed:list', ['-v' => true]);

        expect(Artisan::output())->toContain('Where')->toContain('actor: user, storyfeed.party');
    });

    it('survives storyfeed:cache and is checked from the manifest', function () {
        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

        Artisan::call('storyfeed:cache');
        Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

        expect(Storyfeed::storyWheres())->toBe(['delivery.ship' => ['actor' => ['user']]])
            ->and(fn () => Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish())
            ->toThrow(StoryRoleMismatch::class);
    });

    it('warns in the doctor about stored rows that break a constraint', function () {
        Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish();
        Storyfeed::activity('ship', aDelivery())->actor('Stripe')->publish();
        Storyfeed::activity('ship', aDelivery())->actor(aUser())->publish();
        Storyfeed::activity('ship', aDelivery())->anonymously()->publish();

        expect(Storyfeed::doctor(['role_constraints'])->all())->toBeEmpty();

        Story::for(Delivery::class)->verb('ship')->whereActor(User::class);

        $finding = Storyfeed::doctor(['role_constraints'])->withCode('role_constraints.violated')->sole();

        expect($finding->message)->toContain('`delivery.ship` allows only user as its actor, but 2 live rows have storyfeed.party')
            ->and($finding->subject)->toMatchArray(['type' => 'delivery', 'verb' => 'ship', 'role' => 'actor', 'rows' => 2]);
    });
});
