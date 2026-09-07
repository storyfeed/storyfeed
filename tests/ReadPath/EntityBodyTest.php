<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ShapeSignature;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

function bodyModel(): Customer
{
    $model = new class extends Customer implements HasActivityStreamsType
    {
        protected $table = 'customers';

        public static function activityStreamsType(): ObjectType|string
        {
            return ObjectType::Note;
        }

        public function toFeed(): FeedEntity
        {
            return FeedEntity::make(
                $this->name,
                data: new Collection(['content' => 'app-owned', '$content' => 'also app-owned']),
                content: $this->name === 'cleared' ? null : $this->name,
                mediaType: $this->name === 'cleared' ? null : 'text/markdown',
                attributedTo: $this->name === 'cleared' ? null : 'https://example.test/authors/original',
            );
        }
    };

    Relation::morphMap(['note' => $model::class]);

    return $model;
}

it('persists an authored note and emits its body without a thread or an inferred author', function () {
    $note = bodyModel()::create(['name' => "**Hello**\n\n<script>raw source</script>"]);
    $actor = User::create(['name' => 'Editor', 'email' => 'editor@example.test']);
    $activity = Storyfeed::activity('revise', $note)->actor($actor)->publish();

    $snapshot = Snapshot::where('model_type', 'note')->where('model_id', $note->id)->firstOrFail();
    expect($snapshot->content)->toBe($note->name)
        ->and($snapshot->media_type)->toBe('text/markdown')
        ->and($snapshot->attributed_to)->toBe('https://example.test/authors/original')
        ->and($snapshot->data)->toBe(['content' => 'app-owned', '$content' => 'also app-owned']);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];
    $wire = serialize_one($activity);
    foreach ([$item['object'], $wire['object']] as $object) {
        expect($object['content'])->toBe($note->name)
            ->and($object['mediaType'])->toBe('text/markdown')
            ->and($object['attributedTo'])->toBe('https://example.test/authors/original')
            ->and($object)->not->toHaveKeys(['$v', '$detail']);
    }
    expect($item['object']['data'])->toBe($snapshot->data)
        ->and($item['thread'])->toBeNull()
        ->and($wire['object']['type'])->toBe('Note');

    $note->update(['name' => 'cleared']);
    $item = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($snapshot->fresh()->content)->toBeNull()
        ->and($snapshot->fresh()->media_type)->toBeNull()
        ->and($snapshot->fresh()->attributed_to)->toBeNull()
        ->and($item['object'])->not->toHaveKeys(['content', 'mediaType', 'attributedTo'])
        ->and(serialize_one($activity)['object'])->not->toHaveKeys(['content', 'mediaType', 'attributedTo']);
});

it('keeps the positional API and Arrayable normalization while accepting optional body fields', function () {
    $data = new Collection(['x' => 1]);
    foreach ([new FeedEntity('Label', $data, 'card', '', 'text/x-custom', 'urn:author:1'), FeedEntity::make('Label', $data, 'card', '', 'text/x-custom', 'urn:author:1')] as $entity) {
        expect($entity->data)->toBe(['x' => 1])
            ->and($entity->component)->toBe('card')
            ->and($entity->content)->toBe('')
            ->and($entity->mediaType)->toBe('text/x-custom')
            ->and($entity->attributedTo)->toBe('urn:author:1');
    }
    expect(FeedEntity::make('Label', $data, 'card')->content)->toBeNull();
});

it('preserves empty content and omits unspecified body metadata on both read paths', function () {
    $customer = Customer::create(['name' => 'Acme']);
    $activity = Storyfeed::activity('publish', $customer)->publish();
    $snapshot = Snapshot::where('model_type', 'customer')->where('model_id', $customer->id)->firstOrFail();
    $snapshot->update(['content' => '']);

    foreach ([Storyfeed::feed()->get()->toArray()['items'][0]['object'], serialize_one($activity)['object']] as $object) {
        expect($object['content'])->toBe('')
            ->and($object)->not->toHaveKeys(['mediaType', 'attributedTo']);
    }
});

it('detects adoption of body slots without invalidating legacy shapes or hashing body values', function () {
    $legacy = FeedEntity::make('Label', ['x' => 1]);
    expect(ShapeSignature::for($legacy, Customer::class))->toBe(sha1(json_encode([null, 1, ['x:int']])));
    $body = FeedEntity::make('Label', ['x' => 1], content: 'First');
    expect(ShapeSignature::for($body, Customer::class))->not->toBe(ShapeSignature::for($legacy, Customer::class))
        ->and(ShapeSignature::for($body, Customer::class))->toBe(ShapeSignature::for(FeedEntity::make('Other', ['x' => 2], content: 'Second'), Customer::class));
});
