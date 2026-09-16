<?php

use Spatie\LaravelPackageTools\Package;
use Storyfeed\StoryfeedServiceProvider;

/**
 * Every migration this package ships must be one it also offers.
 *
 * THE GAP THIS CLOSES, and it is a real one rather than a hypothetical.
 * `TestCase::defineDatabaseMigrations()` globs `database/migrations/*.stub`
 * straight off disk, so the suite runs every migration whether or not the
 * package registers it. On 2026-09-15 the body column shipped unregistered:
 * 1249 tests passed on a package no application could install, and it took a
 * consumer's suite failing 5998 times on one undefined column to find it.
 *
 * Migrations here are publish-only — `runsMigrations` is never enabled — so
 * an unregistered file is not merely late, it is unreachable. Nothing else in
 * the suite can notice, because the harness never asks the package what it
 * offers.
 */
function registered_migrations(): array
{
    $provider = new StoryfeedServiceProvider(app());

    // The package object is built by configurePackage(); read what it was told
    // rather than re-deriving it, so this tests the registration itself.
    $package = new Package;
    $package->name('storyfeed');
    $provider->configurePackage($package);

    return $package->migrationFileNames;
}

function shipped_migrations(): array
{
    return collect(glob(__DIR__.'/../../database/migrations/*.php.stub'))
        ->map(fn (string $path): string => str_replace('.php.stub', '', basename($path)))
        ->sort()
        ->values()
        ->all();
}

it('offers every migration it ships, because an unregistered one can never be published', function () {
    $shipped = shipped_migrations();
    $registered = collect(registered_migrations())->sort()->values()->all();

    $unpublishable = array_values(array_diff($shipped, $registered));

    expect($unpublishable)->toBe([], implode('', [
        'These migrations exist on disk and are not registered, so no application ',
        'can publish them: '.implode(', ', $unpublishable).'. Add them to ',
        'hasMigrations() in StoryfeedServiceProvider.',
    ]));
});

it('offers no migration it does not ship, because a published name must resolve to a file', function () {
    $missing = array_values(array_diff(registered_migrations(), shipped_migrations()));

    expect($missing)->toBe([], 'Registered but absent from database/migrations: '.implode(', ', $missing));
});
