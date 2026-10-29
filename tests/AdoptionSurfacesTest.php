<?php

use Symfony\Component\Process\Process;

function adoptionInventory(): array
{
    return json_decode(file_get_contents(dirname(__DIR__).'/resources/adoption/surfaces.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('validates the adoption inventory against the closed format one contract', function () {
    $root = dirname(__DIR__);
    $data = adoptionInventory();
    $schema = json_decode(file_get_contents($root.'/resources/adoption/surfaces.schema.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($data['package']['composer'])->toBe(json_decode(file_get_contents($root.'/composer.json'), true)['name'])
        ->and($data['format'])->toBe(1)
        ->and(array_keys($data))->toEqualCanonicalizing(array_keys($schema['properties']));

    $checkFields = function (array $value, array $definition): void {
        expect(array_diff($definition['required'] ?? [], array_keys($value)))->toBe([])
            ->and(array_diff(array_keys($value), array_keys($definition['properties'])))->toBe([]);
    };
    $checkFields($data, $schema);
    $checkFields($data['package'], $schema['properties']['package']);
    $kinds = [];
    foreach ($schema['$defs']['signal']['oneOf'] as $kind) {
        $kinds[$kind['properties']['kind']['const']] = $kind;
    }
    $checkSignal = function (array $signal) use ($kinds, $checkFields): void {
        expect(array_key_exists($signal['kind'], $kinds))->toBeTrue();
        $checkFields($signal, $kinds[$signal['kind']]);
        foreach ($signal as $key => $value) {
            if (in_array($key, ['kind', 'scope', 'rule'], true)) {
                expect($value)->toBeString();
            } else {
                expect($value)->toBeArray()->not->toBeEmpty();
                foreach ($value as $item) {
                    expect($item)->toBeString();
                }
            }
        }
    };
    foreach ($data['nonSurfaces'] as $nonSurface) {
        $checkFields($nonSurface, $schema['properties']['nonSurfaces']['items']);
    }
    foreach ($data['facts'] as $fact) {
        $checkFields($fact, $schema['properties']['facts']['items']);
        expect(array_keys($fact['computed']))->toBe(['signals']);
        foreach ($fact['computed']['signals'] as $signal) {
            $checkSignal($signal);
            expect($signal['kind'])->not->toBe('rule');
        }
    }
    $ids = array_column($data['surfaces'], 'id');
    expect(array_unique($ids))->toBe($ids);
    foreach ($data['surfaces'] as $surface) {
        $def = $schema['$defs']['surface'];
        $checkFields($surface, $def);
        $checkFields($surface['uses'], $def['properties']['uses']);
        expect($surface['uses'])->not->toBeEmpty()
            ->and($surface['id'])->toMatch('/^[a-z][a-z0-9]*([.-][a-z0-9]+)*$/')
            ->and($surface['layer'])->toBeIn($def['properties']['layer']['enum'])
            ->and($surface['group'])->toBeIn($def['properties']['group']['enum'])
            ->and($surface['status'])->toBeIn($def['properties']['status']['enum']);
        expect(array_diff($surface['applies']['any'], array_column($data['facts'], 'id')))->toBe([]);
        foreach ($surface['handRolled'] as $signal) {
            $checkSignal($signal);
        }
        if (isset($surface['replaces'])) {
            $checkFields($surface['replaces'], $def['properties']['replaces']);
        }
        if ($surface['status'] === 'new') {
            expect($surface['since'] ?? '')->not->toBeEmpty();
        }
        expect($surface['layer'])->toBe('backend')
            ->and($surface['group'])->toBe('activity')
            ->and($surface['applies'])->toBe(['any' => ['has-feeds', 'has-activity-log']])
            ->and($surface['docs'])->toStartWith('https://docs.storyfeed.dev/');
    }
});

it('accounts for every shipped PHP entry point and rejects stale inventory references', function () {
    $root = dirname(__DIR__);
    $data = adoptionInventory();
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (preg_match('/^namespace ([^;]+);/m', $source, $match)) {
            $entries[] = $match[1].'\\'.$file->getBasename('.php');
        } else {
            preg_match_all('/^    function (\w+)\(/m', $source, $functions);
            array_push($entries, ...array_map(fn ($name) => 'function:'.$name, $functions[1]));
        }
    }

    // These shipped trees support the APIs rather than adding app adoption requirements.
    foreach (['workbench', 'database', 'resources'] as $feature) {
        expect(is_dir($root.'/'.$feature))->toBeTrue();
        $entries[] = 'feature:'.$feature;
    }

    $covered = array_column($data['nonSurfaces'], 'ref');
    foreach ($data['nonSurfaces'] as $nonSurface) {
        expect(trim($nonSurface['reason']))->not->toBeEmpty();
    }
    foreach ($data['surfaces'] as $surface) {
        array_push($covered, ...($surface['uses']['classes'] ?? []));
    }

    expect(array_values(array_diff($entries, $covered)))->toBe([])
        ->and(array_values(array_diff($covered, $entries)))->toBe([])
        ->and(array_intersect(array_column($data['nonSurfaces'], 'ref'), array_merge(...array_map(fn ($surface) => $surface['uses']['classes'] ?? [], $data['surfaces']))))->toBe([]);
});

it('preserves the shared has-feeds signals and adds Storyfeed PHP evidence', function () {
    $facts = adoptionInventory()['facts'];
    expect(array_column($facts, 'id'))->toBe(['has-feeds', 'has-activity-log'])
        ->and($facts[0]['description'])->toBe('An app-owned page renders an activity, history or audit timeline.');

    // Frozen from the kit format-one example: changes require coordination, not a second fact.
    expect(array_slice($facts[0]['computed']['signals'], 0, 2))->toBe([
        ['kind' => 'import', 'specifiers' => ['@/components/Kit#ActivityStream']],
        ['kind' => 'file-name', 'globs' => [
            'resources/js/**/*Timeline*.vue',
            'resources/js/**/*Activity*.vue',
            'resources/js/**/*AuditLog*.vue',
        ]],
    ]);
    $php = array_slice($facts[0]['computed']['signals'], 2);
    $classes = array_merge(...array_map(fn ($signal) => [...($signal['extends'] ?? []), ...($signal['implements'] ?? [])], $php));
    expect($classes)->toContain('Storyfeed\\Contracts\\Feedable', 'Storyfeed\\Contracts\\PublishesToFeed', 'Storyfeed\\Stories\\Story', 'Storyfeed\\Feed');
    foreach ($classes as $class) {
        expect(class_exists($class) || interface_exists($class))->toBeTrue();
    }
});

it('matches every fixture outcome through the standards adoption CLI', function (string $app) {
    $root = dirname(__DIR__);
    $expected = json_decode(file_get_contents(__DIR__.'/Fixtures/adoption/expected.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = array_values(array_filter($expected['cases'], fn ($case) => $case['app'] === $app));
    $explicitChecker = getenv('STORYFEED_ADOPTION_CHECKER');
    $isolatedChecker = $root.'/tools/standards/vendor/bin/standards';
    $checker = $explicitChecker ?: (is_file($isolatedChecker) ? $isolatedChecker : $root.'/vendor/bin/standards');
    if (! $explicitChecker && ! is_file($checker)) {
        $this->markTestSkipped('Set STORYFEED_ADOPTION_CHECKER to an isolated standards >=0.11.46 executable; its Illuminate 13 constraint excludes the Laravel 12 lane.');
    }
    expect(is_file($checker))->toBeTrue();
    $process = new Process([
        PHP_BINARY, $checker, 'adoption',
        '--base', __DIR__.'/Fixtures/adoption/'.$app,
        '--inventory', $root.'/resources/adoption/surfaces.json', '--json',
    ], $root);
    $process->mustRun();
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $actual = array_map(fn ($row) => array_intersect_key($row, array_flip(['surface', 'facts', 'row'])), $report['rows']);
    $wanted = array_map(fn ($case) => array_intersect_key($case, array_flip(['surface', 'facts', 'row'])), $cases);
    expect($actual)->toEqual($wanted);
    if ($app === 'migration') {
        $query = array_values(array_filter($report['rows'], fn ($row) => $row['surface'] === 'activity.query'))[0];
        expect(array_column($query['signals'], 'file'))->toContain('app/Queries/ActivityFeedQuery.php');
    }
})->with(['adopted', 'spatie', 'migration', 'no-feeds', 'backend-only']);
