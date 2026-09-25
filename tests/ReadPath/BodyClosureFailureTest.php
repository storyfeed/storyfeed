<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Body\Prose;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Workbench\App\Models\Customer;

/*
 * A DEFERRED BODY THAT THROWS IS REPORTED AND LEFT OUT (todo 1472,
 * 2026-09-25).
 *
 * The docs promised it; core did not do it. feedMedia() returned fine, so
 * LinkResolver's try had nothing to catch — the closure ran later, when the
 * presenter read `$link->body`, and one broken closure failed the whole
 * feed read. The activity must stay, with its label, url and media, and the
 * failure is reported once per class per page like a throwing resolver.
 */

class BrokenBodyCustomer extends Customer
{
    protected $table = 'customers';

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make(url: "/customers/{$context->key()}")
            ->icon('/icon.png')
            ->body('Before.', fn () => throw new RuntimeException('body boom'), 'After.');
    }
}

beforeEach(function () {
    Relation::morphMap(['body-boom' => BrokenBodyCustomer::class]);
});

it('keeps the activity, its link and its other bodies when a deferred body throws', function () {
    $customer = BrokenBodyCustomer::create(['name' => 'Ada']);
    Storyfeed::activity('onboard', $customer)->publish();

    Exceptions::fake();

    $items = Storyfeed::feed()->log()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['object']['label'])->toBe('Ada')
        ->and($items[0]['object']['url'])->toBe("/customers/{$customer->getKey()}")
        ->and($items[0]['object']['media']['icon'])->not->toBeNull()
        ->and($items[0]['object']['body'])->toBe([
            Prose::make('Before.')->toPayload(),
            Prose::make('After.')->toPayload(),
        ]);

    Exceptions::assertReported(RuntimeException::class);
});

it('reports a throwing body once per class per page, and again on the next page', function () {
    foreach (range(1, 3) as $i) {
        Storyfeed::activity('onboard', BrokenBodyCustomer::create(['name' => "Row {$i}"]))->publish();
    }

    Exceptions::fake();

    expect(Storyfeed::feed()->log()->get()->toArray()['items'])->toHaveCount(3);

    Exceptions::assertReportedCount(1);

    Storyfeed::feed()->log()->get()->toArray();

    Exceptions::assertReportedCount(2);
});

it('still throws when the body is read outside a feed read', function () {
    // Off the read path there is nobody to degrade for: a closure that
    // throws should be loud where the app reads it.
    $media = FeedMedia::make()->body(fn () => throw new RuntimeException('loud'));

    expect(fn () => $media->body)->toThrow(RuntimeException::class, 'loud');
});
