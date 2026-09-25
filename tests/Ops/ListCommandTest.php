<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\Grouping\Group;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * storyfeed:list: every definition, route:list-style, with its source line.
 */

beforeEach(function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb('ship')->headline(':actor shipped :object')
            ->anonymousHeadline(':object was shipped')
            ->icon('truck')
            ->intent('success');
        Story::verb('hold')->headline(FeedHeadline::trans('feed.held'));
    });

    Story::for(Customer::class)->verb('ship')->headline(fn () => 'Shipped');
    Story::verb('ship')->grouped(Group::byActors()->headline(':actors shipped :count deliveries'));
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
});

it('lists every definition with its source', function () {
    expect(Artisan::call('storyfeed:list'))->toBe(0);

    expect(Artisan::output())
        ->toContain(':actor shipped :object')
        ->toContain(':object was shipped')
        ->toContain('trans(feed.held)')
        ->toContain('Closure (')
        ->toContain('actors: :actors shipped')
        ->toContain('ListCommandTest.php:')
        ->toContain(DeliveryWasConfirmed::class)
        ->toContain('Showing [5] definitions');
});

it('emits JSON, sorted with wildcards last', function () {
    Artisan::call('storyfeed:list', ['--json' => true]);

    $rows = json_decode(Artisan::output(), true);

    expect(array_map(fn (array $row) => "{$row['type']}.{$row['verb']}", $rows))
        ->toBe(['customer.ship', 'delivery.confirm', 'delivery.hold', 'delivery.ship', '*.ship'])
        ->and($rows[3])->toMatchArray([
            'headline' => ':actor shipped :object',
            'anonymous_headline' => ':object was shipped',
            'icon' => 'truck',
            'intent' => 'success',
        ])
        ->and($rows[3]['source'])->toContain('ListCommandTest.php:');
});

it('filters by type (alias or class) and verb', function () {
    Artisan::call('storyfeed:list', ['--json' => true, '--type' => Delivery::class, '--verb' => 'ship']);

    $rows = json_decode(Artisan::output(), true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('delivery');

    $this->artisan('storyfeed:list', ['--type' => 'courier'])
        ->expectsOutputToContain('No definitions match')
        ->assertSuccessful();
});
