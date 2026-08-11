<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->customer = Customer::create(['name' => 'Acme Co.']);
    $this->delivery = Delivery::create(['customer_id' => $this->customer->id, 'tracking_number' => 'TN-1']);

    Storyfeed::activity()->actor($this->user)->verb('confirm', $this->delivery)->for($this->customer)->publish();
    Storyfeed::activity()->verb('noise')->publish();
});

it('scopes by actor using the morph alias', function () {
    expect(Activity::query()->actor($this->user)->count())->toBe(1);
});

it('scopes by object', function () {
    expect(Activity::query()->object($this->delivery)->count())->toBe(1);
});

it('scopes by verb', function () {
    expect(Activity::query()->verb('confirm')->count())->toBe(1);
});

it('finds activities involving a model in any role', function () {
    expect(Activity::query()->involving($this->customer)->count())->toBe(1)
        ->and(Activity::query()->involving($this->user)->count())->toBe(1)
        ->and(Activity::query()->involving($this->delivery)->count())->toBe(1);
});

it('stores morph aliases in role columns, not class names', function () {
    $activity = Activity::query()->verb('confirm')->first();

    expect($activity->actor_type)->toBe('user')
        ->and($activity->actor_type)->not->toBe(User::class);
});
