<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;

it('hydrates each promoted role through the ordinary entity presenter and preserves its token', function (string $role) {
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
})->with(['origin', 'result', 'instrument']);
