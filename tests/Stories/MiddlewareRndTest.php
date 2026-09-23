<?php

/*
 * R&D (todo 1330): publish middleware on the Story registrar. Evidence for
 * the findings scratchpad, not a build. Branch rnd/feed-middleware.
 */

use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\Support\QueuedActor;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

final class StampTrail
{
    /** @var list<string> */
    public static array $trail = [];

    public function handle(PendingActivity $activity, Closure $next, string $name = 'class'): Activity
    {
        self::$trail[] = $name;

        return $next($activity);
    }
}

beforeEach(function () {
    StampTrail::$trail = [];
    config()->set('storyfeed.parties.fallback', null);
});

it('runs every rung of the ladder, broad to specific, with parameters', function () {
    Story::fallback()->middleware(StampTrail::class.':global');                 // *.*
    Story::for(Delivery::class)->middleware(StampTrail::class.':type');         // delivery.*
    Story::verb('confirm')->headline(':actor confirmed :object')
        ->middleware(StampTrail::class.':verb');                                // *.confirm
    Story::for(Delivery::class)->verb('confirm')
        ->headline(':actor confirmed :object')
        ->middleware(StampTrail::class.':type-verb');                           // delivery.confirm

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'A']))->anonymously()->publish();

    expect(StampTrail::$trail)->toBe(['global', 'type', 'verb', 'type-verb']);
});

it('does not run a type middleware for another type', function () {
    Story::for(Customer::class)->middleware(StampTrail::class.':customer');
    Story::verb('confirm')->headline(':actor confirmed :object');

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'B']))->anonymously()->publish();

    expect(StampTrail::$trail)->toBe([]);
});

it('fills a role the call site left empty, and never overrides one it gave', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Story::for(Delivery::class)->middleware(function (PendingActivity $activity, Closure $next) use ($customer) {
        if (! $activity->hasRole('context')) {
            $activity->context($customer);
        }

        return $next($activity);
    });
    Story::verb('confirm')->headline(':actor confirmed :object');

    $filled = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'C']))->anonymously()->publish();
    $other = Customer::create(['name' => 'Other']);
    $given = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'D']))->anonymously()->context($other)->publish();

    expect($filled->context_id)->toBe($customer->id)
        ->and($given->context_id)->toBe($other->id);
});

it('keeps anonymous distinct from unset: a default-actor middleware sees the difference', function () {
    Story::fallback()->middleware(function (PendingActivity $activity, Closure $next) {
        if (! $activity->hasActor()) {
            $activity->actor('Stripe');       // a Party: a named non-model participant
        }

        return $next($activity);
    });
    Story::verb('confirm')->headline(':actor confirmed :object');

    $unset = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'E']))->publish();
    $anonymous = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'F']))->anonymously()->publish();

    expect($unset->actor->name)->toBe('Stripe')
        ->and($anonymous->actor_type)->toBeNull();
});

it('runs before the default actor ladder, so the authenticated user still wins when middleware defers', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.test']);
    Auth::login($user);

    Story::fallback()->middleware(fn (PendingActivity $activity, Closure $next) => $next($activity));
    Story::verb('confirm')->headline(':actor confirmed :object');

    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'G']))->publish();

    expect($activity->actor_id)->toBe($user->id);
});

it('can decline to publish, which writes nothing', function () {
    Story::for(Delivery::class)->verb('confirm')
        ->headline(':actor confirmed :object')
        ->middleware(fn (PendingActivity $activity, Closure $next) => $activity->discard());

    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'H']))->anonymously()->publish();

    expect($activity->exists)->toBeFalse()->and(Activity::count())->toBe(0);
});

it('refuses a middleware that changes the verb after the definition matched', function () {
    Story::verb('ship')->headline(':actor shipped :object');
    Story::verb('confirm')->headline(':actor confirmed :object')
        ->middleware(fn (PendingActivity $activity, Closure $next) => $next($activity->verb('ship')));

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'I']))->anonymously()->publish();
})->throws(LogicException::class, 'already matched');

it('survives the manifest: class strings and closures compile into the cached arrays', function () {
    Story::for(Delivery::class)->middleware(StampTrail::class.':cached');
    Story::verb('confirm')->headline(':actor confirmed :object');

    $compiled = Storyfeed::compiledStories();

    expect($compiled['middleware'])->toBe(['delivery.*' => [StampTrail::class.':cached']])
        ->and(var_export($compiled['middleware'], true))->toContain('StampTrail:cached');
});

/*
 * Not middleware, but found on the way: the ambient actor of
 * Storyfeed::as() does not travel to a queued job. QueuedActor captures
 * Auth::user() only. Evidence for a defect todo.
 */
it('loses a Storyfeed::as() party across the queue (the gap)', function () {
    $captured = null;

    Storyfeed::as('Stripe', function () use (&$captured) {
        $context = app(Repository::class);
        QueuedActor::capture($context);
        $captured = $context->getHidden(QueuedActor::KEY);
    });

    expect($captured)->toBeNull();   // nothing transported: the worker will record no actor
});
