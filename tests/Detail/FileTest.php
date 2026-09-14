<?php

use Storyfeed\Contracts\FeedDetail;
use Storyfeed\Detail\File;

it('never stores a file url, because the entity regenerates its own', function () {
    $detail = File::make(size: 4404019, mediaType: 'application/zip');
    $expected = [
        '$detail' => 'Storyfeed/Detail/File',
        '$v' => 1,
        'name' => null,
        'size' => 4404019,
        'mediaType' => 'application/zip',
    ];

    // A copy here would age: routes change, disks move, signed links expire,
    // and a snapshot would keep serving the one that was true when written.
    expect($detail)->toBeInstanceOf(FeedDetail::class)
        ->and($detail->toArray())->toBe($expected)
        ->and($detail->toPayload())->toBe($expected)
        ->and($detail->toPayload())->not->toHaveKey('url');

    $stored = json_decode(json_encode($detail->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedDetail::KEY, FeedDetail::VERSION]));

    expect(File::upgrade($props, $stored[FeedDetail::VERSION]))
        ->toBe(['name' => null, 'size' => 4404019, 'mediaType' => 'application/zip']);
});

it('stores bytes as bytes and drops a negative size', function () {
    expect(File::make(size: 900, name: 'archive.zip')->toPayload()['size'])->toBe(900)
        ->and(File::make(size: -1)->toPayload()['size'])->toBeNull()
        ->and(File::make()->toPayload())->toBe(['$detail' => File::name(), '$v' => 1, 'name' => null, 'size' => null, 'mediaType' => null]);
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(File::upgrade(['name' => 'a.zip', 'size' => 12, 'mediaType' => 'application/zip', 'url' => 'stale'], $version))
            ->toBe(['name' => 'a.zip', 'size' => 12, 'mediaType' => 'application/zip']);

        foreach ([[], ['size' => '12', 'name' => 3, 'mediaType' => []]] as $payload) {
            expect(File::upgrade($payload, $version))->toBe(['name' => null, 'size' => null, 'mediaType' => null]);
        }
    }
});
