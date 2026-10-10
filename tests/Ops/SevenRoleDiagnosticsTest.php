<?php

use Storyfeed\Diagnostics\Checks\Check;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;

it('counts a party used solely in a promoted role as used', function (string $role) {
    Storyfeed::activity('confirm')->{$role}('Role-only party')->publish();

    $report = Storyfeed::doctor(['parties']);
    $finding = $report->withCode('parties.used')->sole();

    expect($report->has('parties.unused'))->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject['name'])->toBe('Role-only party')
        ->and($finding->subject['activities'])->toBe(1);
})->with(['origin', 'result', 'instrument', 'location', 'generator']);

it('errors when a singular template names a promoted role never carried', function (string $role) {
    Storyfeed::activity('confirm')->publish();
    Story::verb('confirm')->headline(':'.$role);

    $finding = Storyfeed::doctor(['roles'])->withCode('roles.never_carried')->sole();

    expect($finding->severity)->toBe(Severity::Error)
        ->and($finding->subject)->toBe([
            'key' => '*.confirm',
            'token' => ':'.$role,
            'role' => $role,
            'activities' => 1,
            'pairs' => '(no object).confirm',
        ]);
})->with(['origin', 'result', 'instrument', 'location', 'generator']);

it('stays quiet when a singular template sometimes carries its promoted role', function (string $role) {
    Storyfeed::activity('confirm')->publish();
    Storyfeed::activity('confirm')->{$role}('Present')->publish();
    Story::verb('confirm')->headline(':'.$role);

    expect(Storyfeed::doctor(['roles'])->all())->toBeEmpty();
})->with(['origin', 'result', 'instrument', 'location', 'generator']);

it('suggests all payload roles when singular grammar is missing', function () {
    Storyfeed::activity('confirm')->publish();

    $finding = Storyfeed::doctor(['grammar'])->withCode('grammar.missing')->sole();

    expect($finding->fix->tokens)->toBe([
        ':actor', ':object', ':target', ':context', ':origin', ':result', ':instrument', ':location', ':generator',
    ]);
});

it('discovers aliases recorded solely in promoted roles', function (string $role) {
    Storyfeed::activity('confirm')->{$role}('Role-only party')->publish();

    $check = new class extends Check
    {
        public function name(): string
        {
            return 'aliases';
        }

        public function run(StoryfeedManager $storyfeed): iterable
        {
            return $this->recordedAliases();
        }
    };

    expect($check->run(app(StoryfeedManager::class)))->toBe([(new Party)->getMorphClass()]);
})->with(['origin', 'result', 'instrument', 'location', 'generator']);
