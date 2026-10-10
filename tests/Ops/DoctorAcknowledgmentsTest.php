<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function acknowledgeGap(string $code = 'grammar.missing', array $subject = ['type' => 'delivery', 'verb' => 'confirm']): array
{
    return ['code' => $code, 'subject' => $subject, 'reason' => 'Deliberate application fallback.'];
}

it('accepts an exact pair while retaining raw severity, fixes and visibility', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'ACK-1']))->publish();
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap(subject: ['verb' => 'confirm', 'type' => 'delivery'])]]);

    $report = Storyfeed::doctor(['grammar']);
    $finding = $report->withCode('grammar.missing')->sole();
    expect($finding->severity)->toBe(Severity::Error)
        ->and($finding->fix)->not->toBeNull()
        ->and($finding->acknowledgment)->toBe('Deliberate application fallback.')
        ->and($report->has('grammar.missing'))->toBeTrue()
        ->and($report->acknowledged())->toHaveCount(1)
        ->and($report->count())->toBe(1)
        ->and($report->severity())->toBe(Severity::Warning)
        ->and($report->fixes()->pluck('registry')->all())->toBe(['icons'])
        ->and($report->only(['grammar'])->acknowledged())->toHaveCount(1);

    $this->artisan('storyfeed:doctor --only=grammar --fail-on=error')->assertSuccessful();
    $this->artisan('storyfeed:doctor --only=grammar --fail-on=warning')->assertFailed();

    Storyfeed::activity('archive', Delivery::create(['tracking_number' => 'ACK-2']))->publish();
    $this->artisan('storyfeed:doctor --only=grammar --fail-on=error')->assertFailed();
});

it('renders accepted gaps honestly in text and JSON and excludes their stubs', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'ACK-3']))->publish();
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap(), acknowledgeGap('grammar.icon_missing')]]);

    $this->artisan('storyfeed:doctor --only=grammar --fail-on=warning')
        ->expectsOutputToContain('Acknowledged [error]')
        ->expectsOutputToContain('Reason: Deliberate application fallback.')
        ->expectsOutputToContain('2 acknowledged finding(s).')
        ->expectsOutputToContain('No unacknowledged problems.')
        ->assertSuccessful();
    $this->artisan('storyfeed:doctor --only=grammar --stubs')
        ->expectsOutputToContain('// Nothing to author')
        ->doesntExpectOutputToContain('Reason:')->assertSuccessful();

    expect(Artisan::call('storyfeed:doctor', ['--only' => ['grammar'], '--json' => true, '--stubs' => true]))->toBe(0);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['healthy'])->toBeTrue()->and($json['count'])->toBe(0)
        ->and($json['severity'])->toBeNull()->and($json['acknowledged_count'])->toBe(2)
        ->and(collect($json['findings'])->firstWhere('code', 'grammar.missing')['acknowledgment'])->toBe('Deliberate application fallback.');
});

it('rejects malformed policy without allowing an invalid acceptance', function ($policy) {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'ACK-4']))->publish();
    config(['storyfeed.doctor.acknowledgments' => $policy]);
    $report = Storyfeed::doctor(['grammar']);
    expect($report->has('doctor.acknowledgment_invalid'))->toBeTrue()
        ->and($report->acknowledged())->toBeEmpty()
        ->and($report->withCode('grammar.missing')->sole()->acknowledgment)->toBeNull();
    $this->artisan('storyfeed:doctor --only=grammar --fail-on=error')->assertFailed();
    $this->artisan('storyfeed:doctor --only=grammar')->assertSuccessful();
})->with([
    'not a list' => ['bad'],
    'associative root' => [['entry' => acknowledgeGap()]],
    'partial subject' => [[acknowledgeGap(subject: ['verb' => 'confirm'])]],
    'extra subject field' => [[acknowledgeGap(subject: ['type' => 'delivery', 'verb' => 'confirm', 'count' => 1])]],
    'wrong type' => [[acknowledgeGap(subject: ['type' => 1, 'verb' => 'confirm'])]],
    'unknown code' => [[acknowledgeGap('doctor.check_failed')]],
    'empty reason' => [[['code' => 'grammar.missing', 'subject' => ['type' => 'delivery', 'verb' => 'confirm'], 'reason' => ' ']]],
    'extra entry field' => [[acknowledgeGap() + ['extra' => true]]],
    'duplicate' => [[acknowledgeGap(), acknowledgeGap(subject: ['verb' => 'confirm', 'type' => 'delivery'])]],
]);

it('still applies a valid unrelated entry beside invalid configuration', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'ACK-5']))->publish();
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap(), ['code' => 'bad']]]);
    $report = Storyfeed::doctor(['grammar']);
    expect($report->acknowledged())->toHaveCount(1)->and($report->has('doctor.acknowledgment_invalid'))->toBeTrue();
});

it('does not treat null, strings, asterisks or other object types as equivalent', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'ACK-6']))->publish();
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap(subject: ['type' => null, 'verb' => 'confirm']), acknowledgeGap(subject: ['type' => '*', 'verb' => 'confirm'])]]);
    $report = Storyfeed::doctor(['grammar']);
    expect($report->acknowledged())->toBeEmpty()->and($report->withCode('doctor.acknowledgment_unobserved'))->toHaveCount(2);
});

it('marks absent traffic-dependent entries unobserved and absent registry entries stale', function () {
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap(), acknowledgeGap('axes.verbless_no_grammar', ['axis' => 'retired'])]]);
    $report = Storyfeed::doctor(['grammar', 'axes']);
    expect($report->withCode('doctor.acknowledgment_unobserved')->sole()->severity)->toBe(Severity::Info)
        ->and($report->withCode('doctor.acknowledgment_stale')->sole()->severity)->toBe(Severity::Warning);
    $this->artisan('storyfeed:doctor --only=grammar --only=axes --fail-on=warning')->assertFailed();

    Story::for(Delivery::class)->verb('confirm')->headline(':object confirmed');
    expect(Storyfeed::doctor(['grammar'])->has('doctor.acknowledgment_unobserved'))->toBeTrue();
});

it('does not evaluate excluded or unregistered checks and validates even a selected run', function () {
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('axes.verbless_no_grammar', ['axis' => 'retired'])]]);
    expect(Storyfeed::doctor(['grammar'])->has('doctor.acknowledgment_stale'))->toBeFalse();
    Storyfeed::checks([], merge: false);
    expect(Storyfeed::doctor()->all())->toBeEmpty();
    config(['storyfeed.doctor.acknowledgments' => ['invalid']]);
    expect(Storyfeed::doctor()->has('doctor.acknowledgment_invalid'))->toBeTrue();
    $this->artisan('storyfeed:doctor --list')->doesntExpectOutputToContain('invalid')->assertSuccessful();
    $this->artisan('storyfeed:doctor --only=grammer --fail-on=error')->assertFailed();
});

it('never accepts partial findings or declares staleness from a failed check', function () {
    Storyfeed::checks([new class implements DiagnosticCheck
    {
        public function name(): string
        {
            return 'axes';
        }

        public function run(StoryfeedManager $storyfeed): iterable
        {
            yield Finding::warning('axes.verbless_no_grammar', 'Gap', ['axis' => 'targets']);
            throw new RuntimeException('Incomplete evidence');
        }
    }], merge: false);
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('axes.verbless_no_grammar', ['axis' => 'targets']), acknowledgeGap('axes.verbless_no_grammar', ['axis' => 'retired'])]]);
    $report = Storyfeed::doctor();
    expect($report->has('doctor.check_failed'))->toBeTrue()->and($report->acknowledged())->toBeEmpty()
        ->and($report->has('doctor.acknowledgment_stale'))->toBeFalse();
});

it('pins aggregate type qualification and readers and keeps shared active fixes', function () {
    $subject = ['axis' => 'repeat', 'verb' => 'archive', 'key' => 'repeat.delivery.archive', 'read_by' => 'dashboard'];
    Storyfeed::checks([new class($subject) implements DiagnosticCheck
    {
        public function __construct(private array $subject) {}

        public function name(): string
        {
            return 'aggregates';
        }

        public function run(StoryfeedManager $storyfeed): iterable
        {
            $fix = Fix::make('aggregateGrammar', 'repeat.delivery.archive');
            yield Finding::error('aggregates.missing', 'Gap', $this->subject, $fix);
            yield Finding::error('aggregates.other', 'Another problem with the same fix', [], $fix);
        }
    }], merge: false);
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('aggregates.missing', $subject)]]);
    $report = Storyfeed::doctor();
    expect($report->acknowledged())->toHaveCount(1)->and($report->fixes())->toHaveCount(1);
    foreach (['key' => 'repeat.customer.archive', 'read_by' => null, 'verb' => 'delete'] as $key => $value) {
        config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('aggregates.missing', array_replace($subject, [$key => $value]))]]);
        expect(Storyfeed::doctor()->acknowledged())->toBeEmpty()
            ->and(Storyfeed::doctor()->has('doctor.acknowledgment_unobserved'))->toBeTrue();
    }
});

it('acknowledges a real aggregate cluster and leaves absent clusters advisory', function () {
    $actor = User::create(['name' => 'Ack', 'email' => 'ack@example.com']);
    foreach (range(1, 2) as $index) {
        Storyfeed::activity()->actor($actor)->verb('archive', Delivery::create(['tracking_number' => "ACK-CLUSTER-{$index}"]))->publish();
    }
    $subject = Storyfeed::doctor(['aggregates'])->withCode('aggregates.missing')->sole()->subject;
    expect($subject['key'])->toBe('repeat.delivery.archive')->and($subject['read_by'])->toBeNull();
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('aggregates.missing', $subject)]]);
    expect(Storyfeed::doctor(['aggregates'])->acknowledged())->toHaveCount(1);
    $this->artisan('storyfeed:doctor --only=aggregates --fail-on=error')->assertSuccessful();

    Story::verb('archive')->grouped(Group::on('repeat')->headline(':actor archived :count deliveries'));
    $report = Storyfeed::doctor(['aggregates']);
    expect($report->has('aggregates.missing'))->toBeFalse()
        ->and($report->has('doctor.acknowledgment_unobserved'))->toBeTrue()
        ->and($report->has('doctor.acknowledgment_stale'))->toBeFalse();
});

it('acknowledges a real verbless axis and warns when registry authoring settles it', function () {
    Storyfeed::axes([Axis::make('photo')->key('oa!:oid!:d')->eligibleWhenMembers(min: 2)]);
    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('axes.verbless_no_grammar', ['axis' => 'photo'])]]);
    $report = Storyfeed::doctor(['axes']);
    expect($report->acknowledged())->toHaveCount(1)->and($report->isHealthy())->toBeTrue()
        ->and($report->fixes())->toBeEmpty();
    $this->artisan('storyfeed:doctor --only=axes --fail-on=warning')->assertSuccessful();
    Story::fallback()->grouped(Group::on('photo')->headline(':count things happened to :object'));
    expect(Storyfeed::doctor(['axes'])->has('doctor.acknowledgment_stale'))->toBeTrue();
    $this->artisan('storyfeed:doctor --only=axes --fail-on=warning')->assertFailed();
    $this->artisan('storyfeed:doctor --only=axes --fail-on=error')->assertSuccessful();
});

it('guides exact acknowledgment of a custom-query exclusion without excusing other gaps or readers', function () {
    config(['storyfeed.grouping.curate' => false]);
    $queries = 0;
    Storyfeed::feeds(['portal' => fn (FeedBuilder $feed) => $feed->live()->query(function ($query) use (&$queries) {
        $queries++;
        $query->where('verb', '!=', 'archive');
    })]);
    $actor = User::create(['name' => 'Portal', 'email' => 'portal@example.com']);
    foreach (range(1, 2) as $index) {
        Storyfeed::activity('archive', Delivery::create(['tracking_number' => "QUERY-{$index}"]))->actor($actor)->publish();
    }

    $report = Storyfeed::doctor(['aggregates']);
    $finding = $report->withCode('aggregates.missing')->sole();
    $subject = ['axis' => 'repeat', 'verb' => 'archive', 'key' => 'repeat.delivery.archive', 'read_by' => 'portal'];
    expect($queries)->toBe(0)
        ->and($finding->subject)->toBe($subject)
        ->and($finding->message)->toContain('custom query', 'storyfeed.doctor.acknowledgments', '--only=aggregates --json');
    $this->artisan('storyfeed:doctor --only=aggregates --fail-on=error')->assertFailed();
    expect(Storyfeed::feed('portal')->get()->toArray())->toBeEmpty();

    config(['storyfeed.doctor.acknowledgments' => [acknowledgeGap('aggregates.missing', $subject)]]);
    $accepted = Storyfeed::doctor(['aggregates']);
    expect($accepted->acknowledged())->toHaveCount(1)
        ->and($accepted->withCode('aggregates.missing')->sole()->severity)->toBe(Severity::Error)
        ->and($accepted->fixes())->toBeEmpty();
    $this->artisan('storyfeed:doctor --only=aggregates --fail-on=error')
        ->expectsOutputToContain('Acknowledged [error]')->assertSuccessful();

    foreach (range(1, 2) as $index) {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "QUERY-OTHER-{$index}"]))->actor($actor)->publish();
    }
    expect(Storyfeed::doctor(['aggregates'])->problems()->sole()->subject['verb'])->toBe('confirm');
    $this->artisan('storyfeed:doctor --only=aggregates --fail-on=error')->assertFailed();

    Storyfeed::feeds(['dashboard' => fn (FeedBuilder $feed) => $feed->live()]);
    $changedReaders = Storyfeed::doctor(['aggregates']);
    expect($changedReaders->acknowledged())->toBeEmpty()
        ->and($changedReaders->has('doctor.acknowledgment_unobserved'))->toBeTrue()
        ->and($changedReaders->withCode('aggregates.missing')->firstWhere('subject.verb', 'archive')->subject['read_by'])
        ->toBe('portal, dashboard');
    $this->artisan('storyfeed:doctor --only=aggregates --fail-on=error')->assertFailed();
});
