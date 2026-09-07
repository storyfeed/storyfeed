<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedChange;
use Storyfeed\FeedThread;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Story;
use Workbench\App\Models\Delivery;

class ChangeStory extends Story
{
    public string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|null $verb = 'confirm';

    public string|array|null $objectType = Delivery::class;

    public function headline(): string
    {
        return ':actor confirmed :object';
    }
}

it('records changes through both flat surfaces with a storage-only version', function (bool $story) {
    $change = FeedChange::make(['Status' => ['Draft', 'Ready'], 'Cleared' => ['old', null]]);
    $data = $change->toData(['source' => 'import', '$vendor' => ['$v' => 12]]);
    $object = Delivery::create(['tracking_number' => 'CHANGE']);
    $activity = $story
        ? ChangeStory::record(object: $object, data: $data)
        : Storyfeed::record('confirm', object: $object, data: $data);

    expect($activity->fresh()->data)->toBe($data)
        ->and($data['$change']['$v'])->toBe(1);
    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($node['change'])->toBe($change->toPayload())
        ->and($node['data'])->toBe(['source' => 'import', '$vendor' => ['$v' => 12]]);
    $document = app(ActivitySerializer::class)->activity($activity);
    expect(json_encode($document))->not->toContain('$change', '"change"', 'Draft', 'Cleared');
})->with([false, true]);

it('keeps fluent change thread and data independent of setter order and groups activity facts', function () {
    $change = FeedChange::make(['Status' => [null, 'Ready']]);
    foreach ([false, true] as $dataFirst) {
        $pending = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'CHANGE']))
            ->thread(FeedThread::make('Ready'));
        if ($dataFirst) {
            $pending->data(['source' => 'import'])->change($change);
        } else {
            $pending->change($change)->data(['source' => 'import']);
        }
        $activity = $pending->publish();
        expect($activity->fresh()->data['$change'])->toBe($change->toArray())
            ->and($activity->fresh()->data['$thread']['text'])->toBe('Ready');
    }
    $items = Storyfeed::feed()->get()->toArray()['items'];
    expect($items[0]['kind'])->toBe('group')
        ->and($items[0])->not->toHaveKey('change');
    foreach ($items[0]['children'] as $node) {
        expect($node['change'])->toBe($change->toPayload())
            ->and($node['data'])->toBe(['source' => 'import']);
    }
});

it('reads legacy future and malformed stored changes without rewriting or touching portable details', function (mixed $stored, ?array $expected) {
    $portable = ['$detail' => 'storyfeed-filament/change', '$v' => 99, 'changes' => []];
    $data = ['$change' => $stored, 'portable' => $portable, '$unknown' => true];
    $activity = Storyfeed::record('confirm', Delivery::create(['tracking_number' => 'CHANGE']), data: $data);
    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($node['change'])->toBe($expected)
        ->and($node['data'])->toBe(['portable' => $portable, '$unknown' => true])
        ->and($activity->fresh()->data)->toBe($data);
})->with([
    'legacy' => [['changes' => [['label' => 'Status', 'before' => null, 'after' => 'Ready']]], ['changes' => [['label' => 'Status', 'before' => null, 'after' => 'Ready']]]],
    'future' => [['$v' => 99, 'extra' => true, 'changes' => [['label' => 'Status', 'before' => 'Draft', 'after' => 'Ready', 'extra' => true]]], ['changes' => [['label' => 'Status', 'before' => 'Draft', 'after' => 'Ready']]]],
    'scalar' => ['broken', null],
    'empty' => [[], ['changes' => []]],
    'bad collection' => [['changes' => 'broken'], ['changes' => []]],
    'bad rows' => [['changes' => [false, ['label' => [], 'before' => [], 'after' => false]]], ['changes' => [['label' => '1', 'before' => null, 'after' => 'false']]]],
]);

it('emits null when absent and retains null data', function () {
    $activity = Storyfeed::record('confirm', Delivery::create(['tracking_number' => 'CHANGE']));
    $activity->forceFill(['data' => null])->save();
    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($node['change'])->toBeNull()->and($node['data'])->toBeNull();
});

it('defines missing and invalid versions as one', function () {
    foreach ([null, [], ['$v' => 0], ['$v' => -1], ['$v' => '2']] as $value) {
        expect(FeedChange::versionOf($value))->toBe(1);
    }
    expect(FeedChange::versionOf(['$v' => 99]))->toBe(99)
        ->and(FeedChange::fromArray(null))->toBeNull()
        ->and(FeedChange::make(['Count' => [0, 2], 'Enabled' => [true, false]])->toPayload())->toBe([
            'changes' => [
                ['label' => 'Count', 'before' => '0', 'after' => '2'],
                ['label' => 'Enabled', 'before' => 'true', 'after' => 'false'],
            ],
        ]);
});
