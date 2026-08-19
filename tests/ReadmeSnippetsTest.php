<?php

/**
 * The README's code, executed.
 *
 * WHY THIS FILE EXISTS — do not delete it as duplicate coverage. Every
 * behaviour below is tested elsewhere and better; that is not the point. The
 * point is the SHAPE of the calls, which is the thing only the README asserts.
 *
 * The README is the most-copied artifact in the project and the one nobody
 * re-reads after renaming a method. A reader who copies its recording chain
 * never reaches the page that would have corrected it. So a rename has to fail
 * a build here rather than reach a stranger — the same reasoning that pins the
 * `for()` tombstone messages to the API that actually shipped.
 *
 * Two rules for anyone editing this file:
 *
 * 1. Never string-match the README. Grepping the file for substrings breaks on
 *    every wording change and teaches the next author to loosen the assertion
 *    until it means nothing. Execute the calls instead, in the exact shape the
 *    README teaches them, and let a rename break the call.
 * 2. When you change the README's code, change this file in the same commit.
 *    A test passing against calls the README no longer contains is worse than
 *    no test, because it reads like coverage.
 *
 * Each test names the README section it pins. `$project` and `$file` there map
 * onto the workbench's Customer and Delivery here; the roles are what matter.
 */

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Concerns\AsFeedVerb;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/** The README's `ActivityVerb`, reduced to the case it prints. */
enum ReadmeVerb: string implements FeedVerb
{
    use AsFeedVerb;

    case Upload = 'upload';

    public function activityType(): ActivityType
    {
        return ActivityType::Add;
    }
}

function readmeUser(string $name): User
{
    return User::firstOrCreate(
        ['email' => strtolower($name).'@example.com'],
        ['name' => $name],
    );
}

// ── The opening chain ───────────────────────────────────────────────────────

it('publishes the opening recording chain into the roles its sentence claims', function () {
    $user = readmeUser('Sally');
    $customer = Customer::create(['name' => 'Acme Co']);
    $delivery = Delivery::create(['tracking_number' => '1042', 'customer_id' => $customer->id]);

    // "Sally confirmed Delivery #1042 for Acme Co."
    $activity = Storyfeed::activity()
        ->by($user)
        ->action('confirm', $delivery)
        ->to($customer)
        ->publish();

    expect($activity->verb)->toBe('confirm')
        ->and($activity->actor_id)->toBe($user->id)
        ->and($activity->object_id)->toBe($delivery->id)
        ->and($activity->target_id)->toBe($customer->id);
});

it('reads that activity back from the model the README starts from', function () {
    $customer = Customer::create(['name' => 'Acme Co']);
    $delivery = Delivery::create(['tracking_number' => '1042', 'customer_id' => $customer->id]);

    Storyfeed::activity()->by(readmeUser('Sally'))->action('confirm', $delivery)->to($customer)->publish();

    // The customer is the TARGET here, never the object — a model-first read
    // that only found its own object row would still pass a laxer assertion.
    expect($customer->storyfeed()->get()->items())->toHaveCount(1);
});

// ── Recording ───────────────────────────────────────────────────────────────

it('records with a plain string verb, nothing declared first', function () {
    $project = Customer::create(['name' => 'Project X']);
    $file = Delivery::create(['tracking_number' => 'f-1']);

    $activity = Storyfeed::activity()->by(readmeUser('Sally'))->action('upload', $file)->to($project)->publish();

    expect($activity->verb)->toBe('upload')
        ->and($activity->target_id)->toBe($project->id);
});

it('stores an identical row for every target preposition the README lists', function () {
    $project = Customer::create(['name' => 'Project X']);

    // "`on()`, `with()`, `into()`, `in()` and `for()` set the same slot."
    $rows = collect(['to', 'on', 'with', 'into', 'in', 'for'])
        ->map(fn (string $alias) => Storyfeed::activity()
            ->action('upload', Delivery::create(['tracking_number' => $alias]))
            ->{$alias}($project)
            ->publish())
        ->map(fn ($activity) => [$activity->target_type, $activity->target_id]);

    expect($rows->unique()->values()->all())->toBe([[$project->getMorphClass(), $project->id]]);
});

it('drops an enum case into the chain that already works', function () {
    Storyfeed::verbs(ReadmeVerb::class);

    $project = Customer::create(['name' => 'Project X']);
    $file = Delivery::create(['tracking_number' => 'f-1']);

    $activity = Storyfeed::activity()
        ->by(readmeUser('Sally'))
        ->action(ReadmeVerb::Upload, $file)
        ->to($project)
        ->publish();

    // Same stored row as the string form above: the enum is authoring sugar.
    expect($activity->verb)->toBe('upload')
        ->and($activity->target_id)->toBe($project->id);
});

// ── Reading ─────────────────────────────────────────────────────────────────

it('separates involving() from context() the way the README comments claim', function () {
    $project = Customer::create(['name' => 'Project X']);
    $file = Delivery::create(['tracking_number' => 'f-1']);

    // The project is the TARGET, not the container: it is involved, but nothing
    // happened inside it.
    Storyfeed::activity()->by(readmeUser('Sally'))->action('upload', $file)->to($project)->publish();

    expect(Storyfeed::feed()->involving($project)->log()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->context($project)->log()->get()->items())->toBeEmpty();
});

it('collapses the sample headline the README prints', function () {
    $project = Customer::create(['name' => 'Project X']);

    // "Bob, Sally, and 3 others uploaded files to Project X."
    foreach (['Bob', 'Sally', 'Ann', 'Ravi', 'Mo'] as $name) {
        Storyfeed::activity()
            ->by(readmeUser($name))
            ->action('upload', Delivery::create(['tracking_number' => "{$name}-1"]))
            ->to($project)
            ->publish();
    }

    $items = $project->storyfeed()->summary()->get()->items();

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('actors')
        ->and($items[0]['count'])->toBe(5);
});

it('gives each read mode in the table the granularity its row claims', function () {
    $project = Customer::create(['name' => 'Project X']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        Storyfeed::activity()
            ->by(readmeUser($name))
            ->action('upload', Delivery::create(['tracking_number' => "{$name}-1"]))
            ->to($project)
            ->publish();
    }

    $kinds = fn (FeedBuilder $feed) => collect($feed->get()->items())->pluck('kind')->unique()->values()->all();

    // log(): "every activity, ungrouped". summary(): "multi-axis collapsing".
    expect($kinds($project->storyfeed()->log()))->toBe(['activity'])
        ->and($kinds($project->storyfeed()->summary()))->toBe(['group']);

    // "The default." — an unqualified read matches summary(), not log().
    expect($kinds($project->storyfeed()))->toBe(['group']);

    // "Repeat-only" is live()'s whole distinction from summary(): three
    // different actors are three nodes, where summary() collapsed them to one.
    expect($project->storyfeed()->live()->get()->items())->toHaveCount(3);
});

// ── Named feeds ─────────────────────────────────────────────────────────────

it('filters verbs through the preset the README declares', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed
            ->only(['order.placed', 'order.confirmed', 'order.delivered'])
            ->log(),
    ]);

    $order = Customer::create(['name' => 'Order 1042']);

    Storyfeed::activity()->action('order.confirmed', Delivery::create(['tracking_number' => 'a']))->to($order)->publish();
    Storyfeed::activity()->action('order.margin_reviewed', Delivery::create(['tracking_number' => 'b']))->to($order)->publish();

    $items = $order->storyfeed('customer')->get()->items();

    expect($items)->toHaveCount(1)
        ->and($items[0]['verb'])->toBe('order.confirmed');
});

it('leaves row scope to the caller, exactly as the README warns', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed
            ->only(['order.placed', 'order.confirmed', 'order.delivered'])
            ->log(),
    ]);

    $mine = Customer::create(['name' => 'Order 1042']);
    $someoneElses = Customer::create(['name' => 'Order 1043']);

    foreach ([$mine, $someoneElses] as $i => $order) {
        Storyfeed::activity()
            ->action('order.confirmed', Delivery::create(['tracking_number' => "o-{$i}"]))
            ->to($order)
            ->publish();
    }

    // "returns every order in the system, correctly verb-filtered"
    expect(Storyfeed::feed('customer')->get()->items())->toHaveCount(2)
        ->and($mine->storyfeed('customer')->get()->items())->toHaveCount(1);
});
