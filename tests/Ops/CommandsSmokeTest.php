<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

it('exercises the ops commands end to end', function () {
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);
    ActivityVerb::Confirm->publish(Delivery::create(['tracking_number' => 'TN-1']));

    $this->artisan('storyfeed:verbs', ['--used' => true])->assertSuccessful();
    $this->artisan('storyfeed:doctor')->assertSuccessful();
});

it('warns when an intransitive verb carries an object', function () {
    Storyfeed::verbs(['visit' => 'Arrive']);
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);
    Storyfeed::activity('visit', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('maps to intransitive type Arrive')
        ->assertSuccessful();
});
