<?php

use Illuminate\Filesystem\Filesystem;
use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\BodyMigrations\BodyBlueprint;
use Storyfeed\BodyMigrations\BodyGuard;
use Storyfeed\BodyMigrations\BodyMigrator;
use Storyfeed\BodyMigrations\BodyRegistry;
use Storyfeed\BodyMigrations\Shape;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Tests\BodyMigrations\Fixtures\Attachment;
use Storyfeed\Tests\BodyMigrations\Fixtures\MatureFeed;

it('rejects the two incompatible branch histories at their shared derived position', function () {
    $path = __DIR__.'/../../build/body-branches-'.bin2hex(random_bytes(8));
    try {
        $common = BodyGuard::snapshot(new Attachment);
        MatureFeed::migration($path, 1);
        $branchA = BodyGuard::snapshot(new Attachment(2));
        BodyGuard::assertCompatible($common, $branchA);
        unlink(glob($path.'/*.php')[0]);
        MatureFeed::migration($path, 2);
        $branchB = BodyGuard::snapshot(new Attachment);
        expect($branchA['history'])->toHaveCount(1)->and($branchB['history'])->toHaveCount(1);
        expect(fn () => BodyGuard::assertCompatible($branchA, $branchB))->toThrow(LogicException::class, 'migration history diverges at v2');
        MatureFeed::migration($path, 1);
        $merged = BodyGuard::snapshot(new Attachment(3));
        BodyGuard::assertCompatible($branchA, $merged);
        expect(fn () => BodyGuard::assertCompatible($branchB, $merged))->toThrow(LogicException::class, 'Reconcile branches before release');
    } finally {
        (new Filesystem)->deleteDirectory($path);
    }
});

it('validates every core writer against its declared and released schema', function () {
    $bodies = [KeyValue::make(['State' => null]), MediaObject::make(), FileAttachment::make(), ItemList::make(), Prose::make('Text'), Excerpt::make('Quote'), Component::make('Example')];
    foreach ($bodies as $body) {
        $snapshot = BodyGuard::snapshot($body);
        $released = json_decode(file_get_contents(__DIR__.'/snapshots/'.class_basename($body).'.json'), true, flags: JSON_THROW_ON_ERROR);
        BodyGuard::assertCompatible($released, $snapshot);
        expect($snapshot['schema'])->toBe($released['schema']);
    }
});

it('catches a schema type change with unchanged field names and validates nested schema values', function () {
    $before = BodyGuard::snapshot(FileAttachment::make());
    $after = $before;
    $after['schema']['properties']['size']['type'] = 'string';
    expect(fn () => BodyGuard::assertCompatible($before, $after))->toThrow(LogicException::class, 'changed [size]');
    expect(Shape::matches(['files' => [['bytes' => 'bad']]], ['type' => 'object', 'properties' => ['files' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['bytes' => ['type' => 'integer']]]]]]))->toBeFalse();
});

it('generates anonymous timestamped migrations and a snapshot test using Laravel naming', function () {
    $path = __DIR__.'/../../build/body-generator-'.bin2hex(random_bytes(8));
    $base = app()->basePath();
    $database = app()->databasePath();
    try {
        app()->setBasePath($path);
        app()->useDatabasePath($path.'/database');
        $this->artisan('make:body-migration', ['body' => Attachment::class, 'name' => 'rename_size'])->assertSuccessful();
        $this->artisan('make:body-migration', ['body' => Attachment::class, 'name' => 'drop_legacy'])->assertSuccessful();
        $files = glob($path.'/database/body-migrations/*.php');
        expect($files)->toHaveCount(2)->and(basename($files[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/');
        expect(file_get_contents($files[0]))->toContain('return new class extends BodyMigration', 'Body::table(Attachment::class');
        expect(file_exists($path.'/tests/Feature/AttachmentBodyMigrationTest.php'))->toBeTrue();
        $this->artisan('make:body-migration', ['body' => KeyValue::class, 'name' => 'break_core'])->assertFailed();
    } finally {
        app()->setBasePath($base);
        app()->useDatabasePath($database);
        (new Filesystem)->deleteDirectory($path);
    }
});

it('rejects app migrations for package bodies and reserved field changes', function () {
    $path = __DIR__.'/../../build/body-ownership-'.bin2hex(random_bytes(8));
    try {
        MatureFeed::migration($path, 1);
        $file = glob($path.'/*.php')[0];
        file_put_contents($file, str_replace('Attachment::class', '\\Storyfeed\\Body\\KeyValue::class', file_get_contents($file)));
        expect(fn () => app(BodyMigrator::class)->version(KeyValue::class))->toThrow(LogicException::class, 'Only Storyfeed may migrate');
        expect(fn () => (new BodyBlueprint)->dropField('$v'))->toThrow(InvalidArgumentException::class);
    } finally {
        (new Filesystem)->deleteDirectory($path);
    }
});

it('leaves unknown bodies untouched and recovers when failure logging cannot use a cache', function () {
    $registry = app(BodyRegistry::class);
    $unknown = ['$body' => 'Other/Body', '$v' => 17, 'anything' => true];
    expect($registry->walk($unknown))->toBe($unknown);
    config()->set('cache.default', 'database');
    Storyfeed::body(new Attachment(2));
    $old = (new Attachment)->toPayload();
    expect($registry->walk($old)['$body'])->toBe(BodyRegistry::UNREADABLE);
});

it('enforces declared bounds and rejects unsupported schema keywords', function () {
    expect(Shape::matches(3, ['type' => 'integer', 'minimum' => 4]))->toBeFalse()
        ->and(Shape::matches(6, ['type' => 'integer', 'multipleOf' => 4]))->toBeFalse()
        ->and(Shape::matches('bad', ['type' => 'string', 'pattern' => '^[A-Z]+$']))->toBeFalse()
        ->and(Shape::matches('long', ['type' => 'string', 'maxLength' => 3]))->toBeFalse()
        ->and(Shape::matches([1, 1], ['type' => 'array', 'uniqueItems' => true]))->toBeFalse()
        ->and(Shape::matches('anything', ['type' => 'string', 'format' => 'unimplemented']))->toBeFalse()
        ->and(Shape::matches('OK', ['type' => 'string', 'minLength' => 2, 'pattern' => '^[A-Z]+$']))->toBeTrue();
});

it('preserves present null fields and applies declarative operations in order', function () {
    $body = new BodyBlueprint;
    $body->renameField('size', 'bytes');
    $body->addField('caption', default: 'Default');
    $body->dropField('legacy');
    $body->changeField('bytes', static fn (mixed $value): mixed => $value);
    expect($body->apply(['size' => 12, 'bytes' => null, 'caption' => null, 'legacy' => true]))
        ->toBe(['bytes' => null, 'caption' => null]);
});
