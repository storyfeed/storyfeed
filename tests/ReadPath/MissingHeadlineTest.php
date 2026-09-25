<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * ->missingHeadline(): what a verb's activity reads as once it is redundant.
 * Additive payload keys beside the normal pair, filled only when `redundant`
 * is true; `headline_template` never swaps.
 */

/** The feed's nodes whose verb is the one given. */
function missingHeadlineNodes(string $verb): array
{
    return array_values(array_filter(Storyfeed::feed()->get()->toArray()['items'], fn (array $node) => $node['verb'] === $verb));
}

beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

it('is null while the activity is not redundant', function () {
    Story::verb('confirm')->headline(':actor confirmed :object')->missingHeadline(':actor confirmed a delivery since removed');
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $node = missingHeadlineNodes('confirm')[0];

    expect($node['redundant'])->toBeFalse()
        ->and($node['missing_headline_template'])->toBeNull()
        ->and($node['missing_headline'])->toBeNull();
});

it('carries the verb\'s reading once redundant, beside the unchanged headline', function () {
    Story::verb('confirm')->headline(':actor confirmed :object')->missingHeadline(':actor confirmed a delivery since removed');
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $node = missingHeadlineNodes('confirm')[0];

    expect($node['redundant'])->toBeTrue()
        ->and($node['headline_template'])->toBe(':actor confirmed :object')
        ->and($node['missing_headline_template'])->toBe(':actor confirmed a delivery since removed')
        ->and($node['missing_headline'])->toBeNull();
});

it('is null for a redundant activity whose verb says nothing', function () {
    Story::verb('confirm')->headline(':actor confirmed :object');
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    $node = missingHeadlineNodes('confirm')[0];

    expect($node['redundant'])->toBeTrue()
        ->and($node['missing_headline_template'])->toBeNull();
});

it('is null for a tombstone in a role the verb is not about', function () {
    Story::verb('note')->headline(':actor noted :object for :target')->missingHeadline('never read');
    Storyfeed::activity()->actor($this->ines)->verb('note', $this->acme)->to($this->delivery)->publish();

    $this->delivery->delete();

    $node = missingHeadlineNodes('note')[0];

    expect($node['tombstoned'])->toBe(['target'])
        ->and($node['redundant'])->toBeFalse()
        ->and($node['missing_headline_template'])->toBeNull();
});

it('resolves on the type ladder, by the type the object was', function () {
    Story::for(Delivery::class)->fallback()->missingHeadline(':actor acted on a delivery since removed');
    Story::verb('confirm')->headline(':actor confirmed :object');
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    expect(missingHeadlineNodes('confirm')[0]['missing_headline_template'])->toBe(':actor acted on a delivery since removed');
});

it('resolves optional segments, and runs closures and translations as headlines do', function () {
    Story::verb('confirm')->headline(':actor confirmed :object')->missingHeadline(':actor confirmed a removed delivery[ for :target]');
    Story::verb('route')->headline(':actor routed :object')->missingHeadline(fn () => 'A route since removed');
    Story::verb('ship')->headline(':actor shipped :object')->missingHeadline(FeedHeadline::trans('feed.ship_missing'));
    app('translator')->addLines(['feed.ship_missing' => ':actor shipped a removed delivery'], 'en');

    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('route', $this->delivery)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('ship', $this->delivery)->publish();

    $this->delivery->delete();

    expect(missingHeadlineNodes('confirm')[0]['missing_headline_template'])->toBe(':actor confirmed a removed delivery')
        ->and(missingHeadlineNodes('route')[0]['missing_headline_template'])->toBeNull()
        ->and(missingHeadlineNodes('route')[0]['missing_headline'])->toBe('A route since removed')
        ->and(missingHeadlineNodes('ship')[0]['missing_headline_template'])->toBe(':actor shipped a removed delivery');
});

it('takes the array form', function () {
    defineStories(Verb::make('*.confirm')->fill(['headline' => ':actor confirmed :object', 'missingHeadline' => ':actor confirmed a removed delivery'], '*.confirm'));
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->delete();

    expect(missingHeadlineNodes('confirm')[0]['missing_headline_template'])->toBe(':actor confirmed a removed delivery');
});
