<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * The docs world generator (workbench/docs/generate.php, storyfeed/docs#41)
 * records a world through real models and reads it back. The docs run it
 * against their own checkout; here it runs on a small exported world, in a
 * child process because it boots apps of its own.
 */

/**
 * The generator's files for the fixture world, generated once per process.
 *
 * @return array{payloads: array<string, mixed>, scenes: array<string, mixed>, raw: string}
 */
function docsGenerated(): array
{
    static $generated = null;

    if ($generated === null) {
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storyfeed-docs-'.Str::random(8);
        (new Process([PHP_BINARY, __DIR__.'/../../workbench/docs/generate.php', '--world='.__DIR__.'/Fixtures/docs-world.json', "--out={$out}"]))
            ->setTimeout(120)->mustRun();

        $raw = fn (string $file) => (string) file_get_contents("{$out}/fixture/{$file}");
        $generated = [
            'payloads' => json_decode($raw('payloads.json'), true, flags: JSON_THROW_ON_ERROR),
            'scenes' => json_decode($raw('scenes.json'), true, flags: JSON_THROW_ON_ERROR),
            'raw' => $raw('payloads.json'),
        ];
        File::deleteDirectory($out);
    }

    return $generated;
}

beforeEach(function () {
    ['payloads' => $this->payloads, 'scenes' => $this->scenes, 'raw' => $this->raw] = docsGenerated();
});

it('writes every row\'s Log node, keyed and named by its row, and leaves out the future', function () {
    expect(array_keys($this->payloads))->toEqualCanonicalizing(['p1', 'p2', 'paid', 'removed', 't1', 't2'])
        ->and(array_column($this->payloads, 'id', 'id'))->toBe(array_combine(array_keys($this->payloads), array_keys($this->payloads)))
        ->and($this->raw)->toStartWith("{\n  \"t2\": {\n    \"kind\": \"activity\",")
        ->and($this->raw)->toEndWith("}\n");
});

it('resolves links and pictures through each model\'s feedMedia()', function () {
    $node = $this->payloads['p1'];

    expect($node['actor']['link']['href'])->toBe('/users/101')
        ->and($node['actor']['media']['icon']['src'])->toBe('/avatars/erica.jpg')
        ->and($node['object']['type'])->toBe('order')
        ->and($node['glyph_intent'])->toBe('pending')
        ->and($this->payloads['removed']['object']['body'][0]['content'])->toBe('A sundae.')
        ->and($this->payloads['paid']['actor']['type'])->toBe('storyfeed.party')
        ->and($this->payloads['paid']['data'])->toBe(['amount' => 295]);
});

it('reads each scene in Live with the pack\'s group wording', function () {
    $group = $this->scenes['live']['scene.repeats'];

    expect($group)->toHaveCount(1)
        ->and($group[0]['axis'])->toBe('repeat')
        ->and($group[0]['headline_template'])->toBe(':actor placed :count orders with :target')
        ->and(array_column($group[0]['children'], 'id'))->toEqualCanonicalizing(['p1', 'p2']);
});

it('records a deletion and a composite for real', function () {
    $deletion = $this->scenes['variants']['deletion'];
    $composite = $this->scenes['variants']['composite'];

    expect($deletion['verb'])->toBe('delete')
        ->and($deletion['object']['type'])->toBe('storyfeed.tombstone')
        ->and($deletion['object']['tombstone']['formerType'])->toBe('menu_item')
        ->and($deletion['object']['tombstone']['deleted'])->toBe('2026-07-04T13:00:00.000000Z')
        ->and($composite['live'][0]['axis'])->toBe('composite')
        ->and($composite['live'][0]['headline_template'])->toBe(':actor completed :count tasks')
        ->and(array_column($composite['log'], 'id'))->toEqualCanonicalizing(['t1', 't2']);
});
