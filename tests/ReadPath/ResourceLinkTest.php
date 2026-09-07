<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\FeedResource;
use Workbench\App\Models\Customer;

it('accepts typed resource attachments through every construction path', function () {
    $resource = FeedResource::make('/report.pdf', 'application/pdf', 'Report');

    foreach ([new FeedMedia(attachment: $resource), FeedMedia::make(attachment: $resource), FeedMedia::make()->attachment($resource)] as $media) {
        expect($media->href())->toBeNull()
            ->and($media->media())->toBe([
                'icon' => null, 'image' => null, 'preview' => null,
                'url' => null,
                'attachment' => ['type' => 'Document', 'href' => '/report.pdf', 'mediaType' => 'application/pdf', 'name' => 'Report'],
            ]);
    }

    expect($resource->toArray())->toBe($resource->toPayload())
        ->and($resource->toPayload())->not->toHaveKeys(['$v', '$detail'])
        ->and(FeedMedia::make(attachment: $resource)->attachment(null)->media())->toBeNull();
});

it('carries document links through the payload and AS2 without image properties', function (string $type, string $mime) {
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static string $objectType = 'Document';

        public static string $mime = 'application/pdf';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make(attachment: FeedResource::make('/files/'.$context->id(), self::$mime, $context->label(), self::$objectType))
                ->preview('/preview.png');
        }
    };
    $model::$objectType = $type;
    $model::$mime = $mime;
    Relation::morphMap(['document' => $model::class]);
    $document = $model::create(['name' => 'Report']);
    $activity = Storyfeed::activity('publish', $document)->publish();

    $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];
    expect($object['url'])->toBeNull()
        ->and($object['media']['attachment'])->toBe([
            'type' => $type, 'href' => '/files/'.$document->id, 'mediaType' => $mime, 'name' => 'Report',
        ])
        ->and($object['media']['preview']['src'])->toBe('/preview.png');

    $wire = serialize_one($activity)['object'];
    expect($wire['attachment']['type'])->toBe($type)
        ->and($wire['attachment']['url'])->toBe([
            'type' => 'Link', 'href' => url('/files/'.$document->id), 'mediaType' => $mime, 'name' => 'Report',
        ]);
})->with([
    [ObjectType::Document->value, 'application/pdf'],
    ['https://example.test/Archive', 'application/x-custom-archive'],
]);
