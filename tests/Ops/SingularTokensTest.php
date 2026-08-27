<?php

use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The consumer's shape, reduced: activities that genuinely carry no target,
 * under a template that names one. It rendered a literal italicised
 * "somewhere" inside every sentence — the absent placeholder doing its job,
 * and the reader unable to tell it from a real unknown.
 *
 * These tests pin the two halves the check has to keep apart: NEVER carried
 * (an authoring bug) versus SOMETIMES carried (the placeholder working as
 * designed), and warning versus note for the actor asymmetry.
 */

/** One restore of a document with no target and no context — the finding's shape. */
function restoreOne(?User $actor = null, ?Customer $target = null): void
{
    Storyfeed::activity()
        ->actor($actor)
        ->verb('clause_restored', Delivery::create(['tracking_number' => 'TN-'.fake()->unique()->numberBetween(1, 99999)]))
        ->to($target)
        ->publish();
}

function anActor(string $name = 'Sally'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
}

it('warns when a singular template names a role its activities never carry', function () {
    Storyfeed::grammar(['delivery.clause_restored' => ':actor restored a clause in :object :target']);

    restoreOne(anActor());
    restoreOne(anActor('Bob'));

    $report = Storyfeed::doctor(['roles']);

    expect($report->has('roles.never_carried'))->toBeTrue();

    $finding = $report->withCode('roles.never_carried')->first();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe([
            'key' => 'delivery.clause_restored',
            'token' => ':target',
            'role' => 'target',
            'activities' => 2,
            'pairs' => 'delivery.clause_restored',
        ])
        // The message says the CONSEQUENCE — what the reader sees and cannot
        // distinguish — not merely that a column is null.
        ->and($finding->message)->toContain('absent placeholder')
        ->and($finding->message)->toContain('should never have named one')
        ->and($finding->message)->toContain('`delivery.clause_restored`');
});

it('stays quiet when the role IS sometimes carried — that is the placeholder working', function () {
    Storyfeed::grammar(['delivery.clause_restored' => ':actor restored a clause in :object :target']);

    restoreOne(anActor(), Customer::create(['name' => 'Concur']));
    restoreOne(anActor('Bob'));

    // One of two rows has a target. Warning here would bury the real case
    // under every feed that records a role optionally.
    expect(Storyfeed::doctor(['roles'])->has('roles.never_carried'))->toBeFalse();
});

it('says nothing about roles the template never names', function () {
    Storyfeed::grammar(['delivery.clause_restored' => ':actor restored a clause in :object']);

    restoreOne(anActor());

    // No :target and no :context in the sentence, so their absence in the
    // data is not a fact about anything.
    expect(Storyfeed::doctor(['roles'])->problems())->toBeEmpty();
});

it('reports an always-anonymous actor as a note, not a warning', function () {
    Storyfeed::grammar(['delivery.clause_restored' => ':actor restored a clause in :object']);

    restoreOne();

    $report = Storyfeed::doctor(['roles']);

    // A null actor MEANS anonymous — a documented state, never a system
    // actor. The sentence still reads, so this is reportage and must not
    // fail a build.
    expect($report->has('roles.always_anonymous'))->toBeTrue()
        ->and($report->withCode('roles.always_anonymous')->first()->severity)->toBe(Severity::Info)
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->has('roles.never_carried'))->toBeFalse();
});

it('judges a wildcard template by everything it renders, not one pair', function () {
    Storyfeed::grammar(['*.*' => ':actor did something to :target']);

    $user = anActor();

    // Two pairs share the `*.*` key. One of them carries a target, so the
    // template is fillable and the other pair alone must not condemn it.
    Storyfeed::activity()->actor($user)->verb('clause_restored', Delivery::create(['tracking_number' => 'TN-A']))->publish();
    Storyfeed::activity()->actor($user)->verb('assign', Delivery::create(['tracking_number' => 'TN-B']))
        ->to(Customer::create(['name' => 'Concur']))->publish();

    expect(Storyfeed::doctor(['roles'])->has('roles.never_carried'))->toBeFalse();

    // ...and when NOTHING it renders carries the role, it is one finding for
    // the one edit to make, naming the pairs that proved it.
    Storyfeed::grammar(['*.*' => ':actor did something in :context']);

    $report = Storyfeed::doctor(['roles']);
    $findings = $report->withCode('roles.never_carried');

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->subject['key'])->toBe('*.*')
        ->and($findings->first()->subject['activities'])->toBe(2)
        ->and($findings->first()->message)->toContain('`delivery.assign`')
        ->and($findings->first()->message)->toContain('`delivery.clause_restored`');
});

it('cannot inspect a closure template, and does not pretend to', function () {
    Storyfeed::grammar(['delivery.clause_restored' => fn () => 'restored a clause somewhere']);

    restoreOne(anActor());

    // Closures pre-render; there are no tokens to check. Silence here is the
    // honest answer, and the check must not throw trying to preg_match one.
    expect(Storyfeed::doctor(['roles'])->all())->toBeEmpty();
});

it('is registered, so --only=roles is a real name', function () {
    expect(Storyfeed::doctor(['roles'])->all())->toBeEmpty()
        ->and(collect(Storyfeed::doctor(['roles'])->all())->pluck('code'))
        ->not->toContain('doctor.unknown_check');
});
