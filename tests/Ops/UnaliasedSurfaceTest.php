<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\SurfaceScanner;
use Storyfeed\Testing\StorySurface;
use Storyfeed\Tests\Fixtures\Unaliased\ScaleCustomer;
use Storyfeed\Tests\Fixtures\Unaliased\Stray;
use Workbench\App\Models\Courier;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * A Feedable the enforced morph map has no alias for. A consumer's probe
 * subclasses (`ScaleProject extends Project`, aliased only while a
 * measurement command runs) made `surface` and `hydration` throw on every
 * doctor run in production — the first unaliased class ended the check, so
 * neither said anything about the models that DID have aliases.
 */

beforeEach(function () {
    config()->set('storyfeed.discovery.paths', [
        dirname(__DIR__, 2).'/workbench/app',
        dirname(__DIR__).'/Fixtures/Unaliased',
    ]);

    Customer::$hydrates = false;
});

afterEach(function () {
    Customer::$hydrates = false;
    Customer::$hydrated = [];
});

function publishDelivery(): void
{
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
}

it('reports an unaliased Feedable instead of throwing, and still assesses the rest', function () {
    publishDelivery();

    $report = Storyfeed::doctor(['surface']);

    expect($report->has('doctor.check_failed'))->toBeFalse()
        ->and($report->withCode('surface.unwired')->pluck('subject.model')->all())
        ->toContain(User::class, Customer::class, Courier::class);

    $finding = $report->withCode('surface.unaliased')->firstWhere('subject.model', ScaleCustomer::class);

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject['inherits'])->toBe('customer')
        ->and($finding->message)->toContain('ClassMorphViolationException')
        ->toContain('return `customer` from its getMorphClass()');
});

it('says only "add an alias" when there is no aliased parent to borrow from', function () {
    publishDelivery();

    $finding = Storyfeed::doctor(['surface'])->withCode('surface.unaliased')->firstWhere('subject.model', Stray::class);

    expect($finding->subject['inherits'])->toBeNull()
        ->and($finding->message)->toContain('Give it an alias in Relation::enforceMorphMap().')
        ->not->toContain('getMorphClass()');
});

it('reports it with nothing recorded, because it needs no evidence', function () {
    $report = Storyfeed::doctor(['surface']);

    expect($report->has('surface.unassessable'))->toBeTrue()
        ->and($report->withCode('surface.unaliased')->pluck('subject.model')->all())
        ->toEqualCanonicalizing([ScaleCustomer::class, Stray::class]);
});

it('lets hydration probe every aliased class and skip the rest', function () {
    Customer::$hydrates = true;
    publishDelivery();

    $report = Storyfeed::doctor(['hydration']);

    expect($report->has('doctor.check_failed'))->toBeFalse()
        ->and($report->withCode('hydration.model')->pluck('subject.model')->all())
        ->toContain(Customer::class)
        ->not->toContain(ScaleCustomer::class);
});

it('fails the surface assertion on an unaliased Feedable, unless excepted', function () {
    publishDelivery();

    try {
        StorySurface::assertNoUnwiredSurface(except: [User::class, Customer::class, Courier::class, Stray::class]);
        $this->fail('Expected the unaliased Feedable to be reported.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain(ScaleCustomer::class);
    }

    StorySurface::assertNoUnwiredSurface(except: [User::class, Customer::class, Courier::class, Stray::class, ScaleCustomer::class]);
});

it('refuses a verdict when the surface check itself failed', function () {
    publishDelivery();

    // The helper counted only `surface.unwired`, so a check that died on
    // its first model went green having looked at nothing.
    app()->bind(SurfaceScanner::class, fn () => new class(app()) extends SurfaceScanner
    {
        public function scan(): array
        {
            throw new RuntimeException('scan exploded');
        }
    });

    try {
        StorySurface::assertNoUnwiredSurface();
        $this->fail('Expected the assertion to refuse a failed check.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('could not run')->toContain('scan exploded');
    }
});
