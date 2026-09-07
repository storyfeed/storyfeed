<?php

use Illuminate\Support\Facades\Schema;
use Storyfeed\Contracts\FeedDetail;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The `details` check: what {@see FeedDetail} looks like from the column.
 *
 * Core owns the spec and no implementation, so every assertion here is about
 * what a reader can see WITHOUT knowing a single form's name — which is the
 * property that keeps the spec a spec. A test that had to register a
 * vocabulary to make this check speak would be evidence the architecture had
 * moved.
 */

function recordWithData(array $data): void
{
    $user = User::create(['name' => 'Ines', 'email' => 'ines'.uniqid().'@example.com']);
    $customer = Customer::create(['name' => 'Concur']);

    Storyfeed::activity()
        ->actor($user)
        ->verb('confirm', Delivery::create(['tracking_number' => 'TN-'.uniqid(), 'customer_id' => $customer->id]))
        ->for($customer)
        ->data($data)
        ->publish();
}

function detail(string $name, ?int $version = 1, array $payload = []): array
{
    return array_filter([
        FeedDetail::KEY => $name,
        FeedDetail::VERSION => $version,
    ], fn ($value) => $value !== null) + $payload;
}

it('says nothing on an app that records no details', function () {
    recordWithData(['reason' => 'the customer asked', 'notes' => ['internal' => true]]);

    $report = Storyfeed::doctor(['details']);

    expect($report->all())->toBeEmpty()
        ->and($report->isHealthy())->toBeTrue();
});

it('names each form it finds, as reportage rather than a finding', function () {
    recordWithData(['diff' => detail('acme/change', 1)]);
    recordWithData(['diff' => detail('acme/change', 1)]);

    Snapshot::create([
        'model_type' => 'customer',
        'model_id' => 999,
        'label' => 'Concur',
        'data' => detail('acme/excerpt', 1, ['text' => 'a passage']),
    ]);

    $report = Storyfeed::doctor(['details']);

    $change = $report->all()->firstWhere('subject.form', 'acme/change');
    $excerpt = $report->all()->firstWhere('subject.form', 'acme/excerpt');

    expect($change->severity)->toBe(Severity::Info)
        ->and($change->code)->toBe('details.form')
        ->and($change->subject['activities'])->toBe(2)
        ->and($change->subject['snapshots'])->toBe(0)
        ->and($change->subject['versions'])->toBe('1')
        ->and($change->message)->toContain('Core neither reads nor upgrades it')
        ->and($excerpt->subject['snapshots'])->toBe(1)
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->count())->toBe(0);
});

it('finds a detail alongside the app’s own keys, however deep the map is', function () {
    recordWithData([
        'reason' => 'the customer asked',
        'audit' => ['request' => ['fetch' => detail('acme/fields', 1)]],
    ]);

    expect(Storyfeed::doctor(['details'])->withCode('details.form')->sole()->subject['form'])
        ->toBe('acme/fields');
});

it('stops looking below the depth a detail can legally sit at', function () {
    recordWithData(['a' => ['b' => ['c' => ['d' => ['e' => detail('acme/buried', 1)]]]]]);

    expect(Storyfeed::doctor(['details'])->all())->toBeEmpty();
});

it('does not mistake core’s own reserved key for a broken detail', function () {
    // `$thread` carries a `$v` of its own and no `$detail`. A walk that did
    // not step over it would report every threaded activity in the table.
    $user = User::create(['name' => 'Ines', 'email' => 'thread@example.com']);
    $customer = Customer::create(['name' => 'Concur']);

    Storyfeed::activity()
        ->actor($user)
        ->verb('reply', Delivery::create(['tracking_number' => 'TN-T', 'customer_id' => $customer->id]))
        ->for($customer)
        ->thread(FeedThread::make(text: 'the reply', kind: 'replied', replies: 2))
        ->publish();

    expect(Storyfeed::doctor(['details'])->all())->toBeEmpty();
});

it('reports rows with no version as a fact, because a missing version IS version 1', function () {
    recordWithData(['diff' => detail('acme/change', null)]);

    $report = Storyfeed::doctor(['details']);
    $finding = $report->withCode('details.unversioned')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject['unversioned'])->toBe(1)
        ->and($finding->message)->toContain('DEFINITION, not a fallback')
        ->and($report->isHealthy())->toBeTrue();
});

it('warns when a form declares a later version on some rows and nothing on others', function () {
    recordWithData(['diff' => detail('acme/change', null)]);
    recordWithData(['diff' => detail('acme/change', 2)]);

    $report = Storyfeed::doctor(['details']);
    $finding = $report->withCode('details.version_ambiguous')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject['form'])->toBe('acme/change')
        ->and($finding->subject['unversioned'])->toBe(1)
        ->and($finding->message)->toContain('1→2 upgrade')
        ->and($report->has('details.unversioned'))->toBeFalse();
});

it('warns about a versioned map that no renderer can dispatch on', function () {
    recordWithData(['diff' => [FeedDetail::VERSION => 1, 'changes' => []]]);

    $finding = Storyfeed::doctor(['details'])->withCode('details.untokenized')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject['maps'])->toBe(1)
        ->and($finding->message)->toContain('renders as nothing');
});

it('warns when the name is not a string, because dispatch is by name', function () {
    recordWithData(['diff' => [FeedDetail::KEY => ['acme/change'], FeedDetail::VERSION => 1]]);

    $finding = Storyfeed::doctor(['details'])->withCode('details.malformed_token')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject['types'])->toBe('array');
});

it('never reports an error, whatever it finds', function () {
    recordWithData(['a' => [FeedDetail::VERSION => 1], 'b' => [FeedDetail::KEY => 7], 'c' => detail('acme/x', 3)]);
    recordWithData(['c' => detail('acme/x', null)]);

    $report = Storyfeed::doctor(['details']);

    expect($report->all())->not->toBeEmpty()
        ->and($report->all()->pluck('severity')->all())->not->toContain(Severity::Error);
});

it('is silent when the tables are not there, rather than throwing', function () {
    Schema::drop(config('storyfeed.tables.activities'));
    Schema::drop(config('storyfeed.tables.snapshots'));

    expect(Storyfeed::doctor(['details'])->all())->toBeEmpty();
});
