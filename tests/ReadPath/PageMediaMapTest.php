<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * ONE PICTURE, ONE BUILD, ONE MAP ENTRY (#242, 2026-11-13).
 *
 * A page repeated each decorated entity everywhere it appeared: the group's
 * pinned role, its samples, its featured strip and its child rows. In an app
 * that signs picture URLs that was 368 signed links for 105 pictures, built
 * one by one. Now each distinct entity is decorated once per page, and a
 * paginated page carries a top-level `media` map, keyed by the `media_ref`
 * each entity names it by. The inline copies stay unless an app opts out.
 */

class MediaMapPhoto extends Customer
{
    protected $table = 'customers';

    /** How many times the resolver signed a picture. */
    public static int $signed = 0;

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        self::$signed++;

        return FeedMedia::make(
            url: "/photos/{$context->key()}",
            image: FeedImage::from("https://cdn.example.test/photos/{$context->key()}.jpg?signature=s{$context->key()}"),
        );
    }
}

beforeEach(function () {
    Relation::morphMap(['media_map_photo' => MediaMapPhoto::class]);
    MediaMapPhoto::$signed = 0;
});

/** Three people each review the same three photos, so the groups share every subject. */
function mediaMapFeed(): void
{
    $photos = collect(range(1, 3))->map(fn (int $i) => MediaMapPhoto::create(['name' => "Photo {$i}"]));
    $people = collect(range(1, 3))->map(fn (int $i) => User::create(['name' => "Reviewer {$i}", 'email' => "r{$i}@example.test"]));

    foreach ($people as $p => $person) {
        foreach ($photos as $photo) {
            Storyfeed::activity('review', $photo)->actor($person)->publishedAt(now()->subHours(3 - $p))->publish();
        }
    }
}

/** @return list<array<string, mixed>> every entity array in a node tree */
function mediaMapEntities(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $found = array_key_exists('media_ref', $value) && array_key_exists('type', $value) ? [$value] : [];

    foreach ($value as $child) {
        array_push($found, ...mediaMapEntities($child));
    }

    return $found;
}

it('maps each distinct picture once, and every reference resolves to the inline copy', function () {
    mediaMapFeed();

    $payload = Storyfeed::feed()->cursorPaginate(20)->toArray();
    $entities = mediaMapEntities($payload['data']);
    $photos = array_filter($entities, fn (array $e) => $e['type'] === 'media_map_photo');
    $distinct = array_unique(array_map(fn (array $e) => $e['type'].':'.$e['id'], $entities));

    expect(collect($payload['data'])->pluck('kind'))->toContain('group')
        // The page repeats the photos; the map holds each once.
        ->and(count($photos))->toBeGreaterThan(3)
        ->and(count($entities))->toBeGreaterThan(count($payload['media']))
        ->and($payload['media'])->toHaveCount(count($distinct));

    foreach ($entities as $entity) {
        expect($payload['media'])->toHaveKey($entity['media_ref'])
            ->and($payload['media'][$entity['media_ref']])->toBe(['link' => $entity['link'], 'media' => $entity['media']]);
    }

    $signed = array_filter(array_column(array_column($payload['media'], 'media'), 'image'));
    expect(array_column($signed, 'src'))->toEqualCanonicalizing([
        'https://cdn.example.test/photos/1.jpg?signature=s1',
        'https://cdn.example.test/photos/2.jpg?signature=s2',
        'https://cdn.example.test/photos/3.jpg?signature=s3',
    ]);
});

it('signs each distinct picture once per page, however many places show it', function () {
    mediaMapFeed();

    $items = Storyfeed::feed()->get()->toArray();
    $photos = array_filter(mediaMapEntities($items), fn (array $e) => $e['type'] === 'media_map_photo');

    expect(count($photos))->toBeGreaterThan(3)
        ->and(MediaMapPhoto::$signed)->toBe(3);

    // The next page is a new build: it signs afresh rather than reusing.
    Storyfeed::feed()->get()->toArray();

    expect(MediaMapPhoto::$signed)->toBe(6);
});

it('leaves the inline copies out of a paginator when the app opts out', function () {
    mediaMapFeed();
    config(['storyfeed.payload.inline_media' => false]);

    $payload = Storyfeed::feed()->cursorPaginate(20)->toArray();
    $entities = mediaMapEntities($payload['data']);

    expect($entities)->not->toBeEmpty();

    foreach ($entities as $entity) {
        expect($entity)->not->toHaveKey('media')
            ->and($entity)->not->toHaveKey('link')
            ->and($payload['media'][$entity['media_ref']]['media'])->toHaveKey('image');
    }

    // get() is not a transport: its nodes keep the inline copies.
    expect(Storyfeed::feed()->get()->toArray()[0])->toHaveKey('kind');
    expect(mediaMapEntities(Storyfeed::feed()->get()->toArray())[0])->toHaveKey('media');
});

it('carries a null reference off a page', function () {
    $photo = MediaMapPhoto::create(['name' => 'Loose']);
    $activity = Storyfeed::activity('review', $photo)->publish();

    $node = app(NodePresenter::class)->activityNode($activity->fresh());

    expect($node['object'])->toHaveKey('media_ref', null)
        ->and($node['object']['media']['image']['src'])->toContain('signature=s');
});
