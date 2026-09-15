<?php

use Storyfeed\Body\MediaObject;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\FeedLink;
use Storyfeed\FeedResource;
use Storyfeed\MediaSlot;

it('stores a slot name and no image, because the resolver mints the picture at read time', function () {
    $body = MediaObject::make(
        subject: 'N201 Saffron Butter Rice',
        content: 'Basmati replaces Jasmine.',
        image: MediaSlot::Icon,
    );

    $expected = [
        '$body' => 'Storyfeed/Body/MediaObject',
        '$v' => 1,
        'subject' => 'N201 Saffron Butter Rice',
        'content' => 'Basmati replaces Jasmine.',
        'image' => 'icon',
        'attachments' => [],
        'footnote' => null,
    ];

    // No src, no mediaType, no width, no height, no alt: the FeedImage that
    // feedMedia() mints carries all of them, and a copy here would age.
    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected)
        ->and(array_keys($body->toPayload()))->not->toContain('src', 'url', 'width', 'height', 'alt', 'mediaType');

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));

    expect(MediaObject::upgrade($props, $stored[FeedBody::VERSION]))
        ->toBe(['subject' => 'N201 Saffron Butter Rice', 'content' => 'Basmati replaces Jasmine.', 'image' => 'icon', 'attachments' => [], 'footnote' => null]);
});

it('reads in the order it renders: subject, content, image, attachments, footnote', function () {
    // The footnote is last in the signature because it is subordinate to
    // everything above it, and the reading order of the two should match.
    expect(array_keys(MediaObject::make()->toPayload()))
        ->toBe(['$body', '$v', 'subject', 'content', 'image', 'attachments', 'footnote'])
        ->and(array_keys(MediaObject::upgrade([], 1)))
        ->toBe(['subject', 'content', 'image', 'attachments', 'footnote']);
});

it('is all-optional, so a block with only attachments is a file list and no second form is needed', function () {
    $pdf = FeedResource::make('https://example.test/n201-v4.pdf', 'application/pdf', 'n201-v4.pdf');

    expect(MediaObject::make()->toPayload())
        ->toBe(['$body' => MediaObject::name(), '$v' => 1, 'subject' => null, 'content' => null, 'image' => null, 'attachments' => [], 'footnote' => null])
        ->and(MediaObject::make(attachments: [$pdf])->toPayload()['attachments'])
        ->toBe([['type' => 'Document', 'href' => 'https://example.test/n201-v4.pdf', 'mediaType' => 'application/pdf', 'name' => 'n201-v4.pdf']])
        ->and(MediaObject::make(subject: 'Minutes', content: 'Two items carried.')->toPayload()['image'])->toBeNull();
});

it('names the files it draws rather than deferring to whatever the entity holds', function () {
    /*
     * `attachments` was a bool until 2026-09-10: `true` meant "draw whatever
     * entity.media.attachments has". That could never say WHICH files, so two
     * blocks on one entity could not differ and neither could say what it was
     * about. The consumer says what a block contains; the renderer decides how
     * it looks.
     */
    $spec = FeedResource::make('https://example.test/n201-v4.pdf', 'application/pdf', 'n201-v4.pdf');
    $photo = FeedResource::make('https://example.test/plated.jpg', 'image/jpeg', 'plated.jpg', 'Image');

    expect(MediaObject::make(subject: 'N201', attachments: [$spec, $photo])->toPayload()['attachments'])
        ->toBe([$spec->toPayload(), $photo->toPayload()])
        // A list of VALUES, which a form may hold; a list of forms is what
        // details are forbidden. Nothing nests here.
        ->and(MediaObject::make(attachments: [$spec])->toPayload()['attachments'][0])
        ->not->toHaveKey(FeedBody::KEY);

    // Anything that is not a FeedResource is not a file, and a list stays a list.
    expect(MediaObject::make(attachments: ['n201-v4.pdf', null, $spec, ['href' => 'x']])->toPayload()['attachments'])
        ->toBe([$spec->toPayload()]);
});

it('produces a byte-identical row from the fluent form, which is sugar and not a second form', function () {
    $pdf = FeedResource::make('https://example.test/n201-v4.pdf', 'application/pdf', 'n201-v4.pdf');

    $fluent = MediaObject::make(subject: 'N201', content: 'Basmati.')->withIcon()->withAttachments($pdf);
    $named = MediaObject::make(subject: 'N201', content: 'Basmati.', image: MediaSlot::Icon, attachments: [$pdf]);

    expect(json_encode($fluent->toArray()))->toBe(json_encode($named->toArray()))
        ->and(MediaObject::make()->withPreview()->toPayload()['image'])->toBe('preview')
        ->and(MediaObject::make()->withImage()->toPayload()['image'])->toBe('image');

    /*
     * The fluent form needs at least one file, so that retiring the bool lands
     * as an error here too. A bare withAttachments() is the shape that went
     * away, and accepting it would make an upgrade that did nothing look like
     * one that worked.
     */
    expect(fn () => MediaObject::make()->withAttachments())->toThrow(ArgumentCountError::class);
});

it('names at most one slot, and a second one throws rather than replacing the first', function () {
    // A block naming two slots is a block asking to be drawn twice. Last-wins
    // would turn the mistake into a silent layout at the one moment the
    // author is present to hear about it.
    expect(fn () => MediaObject::make(image: MediaSlot::Icon)->withImage())
        ->toThrow(LogicException::class, 'already names `icon`')
        ->and(fn () => MediaObject::make()->withPreview()->withPreview())
        ->toThrow(LogicException::class);

    // Attachments are not a slot; adding them after a slot is fine.
    $pdf = FeedResource::make('https://example.test/n201-v4.pdf');
    expect(MediaObject::make()->withIcon()->withAttachments($pdf)->toPayload()['image'])->toBe('icon');
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    $blank = ['subject' => null, 'content' => null, 'image' => null, 'attachments' => [], 'footnote' => null];
    $file = ['type' => 'Document', 'href' => 'https://example.test/n201-v4.pdf', 'mediaType' => null, 'name' => null];

    foreach ([1, 0, 999] as $version) {
        expect(MediaObject::upgrade(['subject' => 'A', 'content' => 'B', 'image' => 'preview', 'attachments' => [$file], 'footnote' => 'C', 'src' => 'stale'], $version))
            ->toBe(['subject' => 'A', 'content' => 'B', 'image' => 'preview', 'attachments' => [$file], 'footnote' => 'C']);

        foreach ([[], ['subject' => 3, 'content' => [], 'image' => 7, 'attachments' => 'yes', 'footnote' => 9]] as $payload) {
            expect(MediaObject::upgrade($payload, $version))->toBe($blank);
        }
    }

    // A slot this vocabulary never issued — `url`, which is never a slot, or
    // a case a later version adds — draws the text and no picture.
    foreach (['url', 'hero', ''] as $unknown) {
        expect(MediaObject::upgrade(['image' => $unknown], 1)['image'])->toBeNull();
    }
});

it('upgrades a row that said `attachments: true` to a row that names no files', function () {
    /*
     * The bool does not survive and there is no migration: an already-written
     * row draws no files rather than throwing, and the consumer re-authors it
     * the next time they touch the block. Empty is a state this vocabulary
     * already has and already renders as nothing, so nothing became invalid.
     */
    foreach ([true, false, 1, 'true', null] as $legacy) {
        expect(MediaObject::upgrade(['subject' => 'N201', 'attachments' => $legacy], 1))
            ->toBe(['subject' => 'N201', 'content' => null, 'image' => null, 'attachments' => [], 'footnote' => null]);
    }
});

it('keeps a stored file only when it has an href, and mints it back into the shape it writes', function () {
    $stored = [
        ['type' => 'Image', 'href' => 'https://example.test/plated.jpg', 'mediaType' => 'image/jpeg', 'name' => 'plated.jpg'],
        // No href: a file with no location is not a file, and dropping it is
        // the malformed-subject rule one field over — never a broken row.
        ['type' => 'Document', 'mediaType' => 'application/pdf', 'name' => 'n201-v4.pdf'],
        ['href' => ''],
        'n201-v4.pdf',
        7,
        // Missing everything but the href: the defaults are minted back, so an
        // upgraded row carries exactly what toPayload() would have written.
        ['href' => 'https://example.test/n201-v4.pdf'],
    ];

    expect(MediaObject::upgrade(['attachments' => $stored], 1)['attachments'])->toBe([
        ['type' => 'Image', 'href' => 'https://example.test/plated.jpg', 'mediaType' => 'image/jpeg', 'name' => 'plated.jpg'],
        ['type' => 'Document', 'href' => 'https://example.test/n201-v4.pdf', 'mediaType' => null, 'name' => null],
    ]);
});

it('takes a subject that is text or a subject that leads somewhere', function () {
    /*
     * THE TWO ARE NOT INTERCHANGEABLE. A string is a title; a FeedLink is a
     * title that is also the row's way in. The whole reason this field widened
     * is so a consumer never has to open a renderer's view file to make a title
     * clickable — the route that produced a bordered card saying "Open the
     * conversation" three times in one viewport.
     */
    expect(MediaObject::make(subject: 'N201 Saffron Butter Rice')->toPayload()['subject'])
        ->toBe('N201 Saffron Butter Rice');

    // A null href stores no location: the renderer resolves it against the
    // entity at read time, the same way `image: "icon"` resolves.
    expect(MediaObject::make(subject: FeedLink::make('N201 Saffron Butter Rice'))->toPayload()['subject'])
        ->toBe(['label' => 'N201 Saffron Butter Rice', 'href' => null]);

    expect(MediaObject::make(subject: FeedLink::make('The notice', 'https://example.test/n/9'))->toPayload()['subject'])
        ->toBe(['label' => 'The notice', 'href' => 'https://example.test/n/9']);
});

it('does not make an old string subject clickable when the field widens', function () {
    /*
     * Every row written before FeedLink existed has a string here. Upgrading
     * one must leave it a string: an available target read as an instruction is
     * the defect this vocabulary keeps producing, and a whole feed of titles
     * silently becoming links is its largest available form.
     */
    expect(MediaObject::upgrade(['subject' => 'N201 Saffron Butter Rice'], 1)['subject'])
        ->toBe('N201 Saffron Butter Rice');

    expect(MediaObject::upgrade(['subject' => ['label' => 'A dish', 'href' => null]], 1)['subject'])
        ->toBe(['label' => 'A dish', 'href' => null]);

    // Malformed degrades to no subject at all, never to a broken row.
    foreach ([['label' => ''], ['href' => 'https://example.test'], 7, []] as $malformed) {
        expect(MediaObject::upgrade(['subject' => $malformed], 1)['subject'])->toBeNull();
    }
});

it('takes a footnote that is text or a footnote that leads somewhere', function () {
    /*
     * "Approved by Jasper" has to be RECORDED without claiming equal weight
     * with the sentence — subtle, which is the whole specification. A link is
     * allowed so the credit can lead to the person or to the approval; the two
     * mean different things, exactly as they do on `subject`.
     */
    expect(MediaObject::make(footnote: 'Approved by Jasper')->toPayload()['footnote'])
        ->toBe('Approved by Jasper');

    expect(MediaObject::make(footnote: FeedLink::make('Approved by Jasper'))->toPayload()['footnote'])
        ->toBe(['label' => 'Approved by Jasper', 'href' => null]);

    expect(MediaObject::make(footnote: FeedLink::make('Approved by Jasper', 'https://example.test/approvals/9'))->toPayload()['footnote'])
        ->toBe(['label' => 'Approved by Jasper', 'href' => 'https://example.test/approvals/9']);

    // It is one line of small print and nothing else. A footnote that grew a
    // picture or a heading would be a block asking to be born.
    expect(array_keys(MediaObject::make(footnote: 'Approved by Jasper')->toPayload()))
        ->not->toContain('footnoteImage', 'footnoteHeading');
});

it('does not make a string footnote clickable, for the same reason a subject is not', function () {
    /*
     * The field is new, so no row has an old string in it yet — but the rule is
     * read-time and applies to every row this class ever meets. A string is
     * text that leads nowhere; an available target is not an instruction.
     */
    expect(MediaObject::upgrade(['footnote' => 'Approved by Jasper'], 1)['footnote'])
        ->toBe('Approved by Jasper');

    expect(MediaObject::upgrade(['footnote' => ['label' => 'Approved by Jasper', 'href' => null]], 1)['footnote'])
        ->toBe(['label' => 'Approved by Jasper', 'href' => null]);

    // Malformed degrades to no footnote at all, never to a broken row.
    foreach ([['label' => ''], ['href' => 'https://example.test'], 7, []] as $malformed) {
        expect(MediaObject::upgrade(['footnote' => $malformed], 1)['footnote'])->toBeNull();
    }
});
