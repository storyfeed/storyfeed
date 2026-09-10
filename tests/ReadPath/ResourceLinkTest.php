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
    $payload = ['type' => 'Document', 'href' => '/report.pdf', 'mediaType' => 'application/pdf', 'name' => 'Report'];

    foreach ([new FeedMedia(attachments: [$resource]), FeedMedia::make(attachments: [$resource]), FeedMedia::make()->attachments([$resource])] as $media) {
        expect($media->href())->toBeNull()
            ->and($media->attachments)->toBe([$resource])
            ->and($media->media())->toBe([
                'icon' => null, 'image' => null, 'preview' => null,
                'url' => null,
                'attachments' => [$payload],
            ]);
    }

    expect($resource->toArray())->toBe($resource->toPayload())
        ->and($resource->toPayload())->not->toHaveKeys(['$v', '$detail'])
        ->and(FeedMedia::make(attachments: [$resource])->attachments([])->media())->toBeNull()
        ->and(FeedMedia::make()->attachments)->toBe([]);
});

it('keeps the attachment list in the order it was given, as a list', function () {
    $resources = [
        'b' => FeedResource::make('/b.pdf', name: 'B'),
        'a' => FeedResource::make('/a.pdf', name: 'A'),
        'c' => FeedResource::make('/c.zip', 'application/zip', 'C', 'https://example.test/Archive'),
    ];

    $media = FeedMedia::make(attachments: new ArrayIterator($resources));

    expect($media->attachments)->toBe(array_values($resources))
        ->and(array_column($media->media()['attachments'], 'href'))->toBe(['/b.pdf', '/a.pdf', '/c.zip'])
        ->and(json_encode($media->media()['attachments']))->toStartWith('[{');
});

it('rejects an attachment that is not a FeedResource', function () {
    FeedMedia::make(attachments: ['/report.pdf']);
})->throws(TypeError::class);

it('always carries the attachments key on a media object, empty when the media is only images', function () {
    expect(FeedMedia::make(preview: '/thumb.png')->media())->toBe([
        'icon' => null, 'image' => null,
        'preview' => ['src' => '/thumb.png', 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null],
        'url' => null,
        'attachments' => [],
    ]);
});

it('carries document links through the payload and AS2 without image properties', function (string $type, string $mime) {
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static string $objectType = 'Document';

        public static string $mime = 'application/pdf';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make(attachments: [FeedResource::make('/files/'.$context->id(), self::$mime, $context->label(), self::$objectType)])
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
        ->and($object['media']['attachments'])->toBe([[
            'type' => $type, 'href' => '/files/'.$document->id, 'mediaType' => $mime, 'name' => 'Report',
        ]])
        ->and($object['media']['preview']['src'])->toBe('/preview.png');

    // One `attachment` property holding an array, even for a single value:
    // the term is non-functional in AS2 and a peer parses one shape.
    $wire = serialize_one($activity)['object'];
    expect($wire['attachment'])->toBe([[
        'type' => $type,
        'url' => ['type' => 'Link', 'href' => url('/files/'.$document->id), 'mediaType' => $mime, 'name' => 'Report'],
    ]]);
})->with([
    [ObjectType::Document->value, 'application/pdf'],
    ['https://example.test/Archive', 'application/x-custom-archive'],
]);

it('serializes many attachments as one AS2 property in resolver order, and none as no property', function () {
    $model = new class extends Customer
    {
        protected $table = 'customers';

        /** @var list<string> */
        public static array $files = [];

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make(preview: '/preview.png')
                ->attachments(array_map(fn (string $file) => FeedResource::make('/files/'.$file, name: $file), self::$files));
        }
    };
    Relation::morphMap(['bundle' => $model::class]);
    $bundle = $model::create(['name' => 'Bundle']);
    $activity = Storyfeed::activity('publish', $bundle)->publish();

    $model::$files = ['minutes.pdf', 'budget.xlsx', 'photos.zip'];
    $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];
    expect(array_column($object['media']['attachments'], 'name'))->toBe(['minutes.pdf', 'budget.xlsx', 'photos.zip']);

    $wire = serialize_one($activity)['object'];
    expect($wire['attachment'])->toHaveCount(3)
        ->and(array_column(array_column($wire['attachment'], 'url'), 'href'))
        ->toBe([url('/files/minutes.pdf'), url('/files/budget.xlsx'), url('/files/photos.zip')])
        ->and(array_column($wire['attachment'], 'type'))->toBe(['Document', 'Document', 'Document']);

    $model::$files = [];
    expect(Storyfeed::feed()->get()->toArray()['items'][0]['object']['media']['attachments'])->toBe([])
        ->and(serialize_one($activity)['object'])->not->toHaveKey('attachment')
        ->and(serialize_one($activity)['object']['preview']['href'])->toBe(url('/preview.png'));
});
