<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;

it('hydrates each promoted role through the ordinary entity presenter and preserves its token', function (string $role) {
    $previous = [Customer::$hydrates, Customer::$hydrated, Customer::$lastContext];

    try {
        Customer::$hydrates = true;
        $entity = Customer::create(['name' => 'Named entity']);
        Storyfeed::grammar(['*.*' => "via :{$role}"]);
        $activity = Storyfeed::activity('confirm')->{$role}($entity)->publish();
        $node = app(NodePresenter::class)->activityNode($activity->fresh());
        $control = app(NodePresenter::class)->activityNode(Storyfeed::activity('confirm')->target($entity)->publish());

        expect($node[$role])->toBe($control['target'])
            ->and($node['headline_template'])->toBe("via :{$role}")
            ->and(serialize_one($activity)['summary'])->toBe('via Named entity');

        DB::table($entity->getTable())->where('id', $entity->id)->delete();
        $page = Storyfeed::feed()->log()->get()->toArray();
        expect($page['items'])->toHaveCount(2);
        $item = collect($page['items'])->firstWhere('id', $activity->uid);
        expect($item[$role]['label'])->toBe('Named entity')
            ->and($item[$role]['url'])->toBeNull();
    } finally {
        // These are process-wide fixture switches, not application/container
        // state. Restore them even when an assertion in this scenario fails.
        [Customer::$hydrates, Customer::$hydrated, Customer::$lastContext] = $previous;
    }

    // Regression: run the next consumer in this same test, independent of
    // Pest's seed. A leaked hydration flag bypasses the named-feed URL branch
    // even though the resolver still receives the correct feed context.
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->log()->only(['onboard'])]);
    $next = Customer::create(['name' => 'Next consumer']);
    Storyfeed::activity('onboard', $next)->publish();
    $item = Storyfeed::feed('kitchen')->get()->items()[0];

    expect(Customer::$lastContext?->feed())->toBe('kitchen')
        ->and($item['object']['url'])->toBe("/kitchen/customers/{$next->id}");
})->with(['origin', 'result', 'instrument']);
