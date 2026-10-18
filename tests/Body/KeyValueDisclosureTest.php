<?php

use Storyfeed\Body\KeyValue;

it('carries optional evidence disclosure without removing any rows', function () {
    $plain = KeyValue::make(['Address' => KeyValue::verbatim('192.0.2.4')])->toPayload();
    $disclosed = KeyValue::make(['Address' => KeyValue::verbatim('192.0.2.4')])->disclosure('Request evidence')->toPayload();

    expect($disclosed)->toEqual([...$plain, 'disclosure' => 'Request evidence'])
        ->and(KeyValue::upgrade($disclosed, 3)['disclosure'])->toBe('Request evidence')
        ->and(KeyValue::upgrade($disclosed, 3)['items'])->toBe(KeyValue::upgrade($plain, 3)['items']);
});

it('leaves historical bodies unchanged and treats blank or malformed disclosure as absent', function () {
    $plain = KeyValue::make(['Status' => 'Ready'])->toPayload();
    foreach ([null, '', '  '] as $summary) {
        expect(KeyValue::make(['Status' => 'Ready'])->disclosure($summary)->toPayload())->toBe($plain);
    }
    foreach ([null, '', ' ', 12, [], false] as $summary) {
        expect(KeyValue::upgrade([...$plain, 'disclosure' => $summary], 3))
            ->toBe(KeyValue::upgrade($plain, 3));
    }
    expect(KeyValue::make(['Status' => 'Ready'])->disclosure('Evidence')->disclosure(null)->toPayload())->toBe($plain);
});
