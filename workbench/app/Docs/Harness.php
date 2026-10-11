<?php

namespace Workbench\App\Docs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Orchestra\Testbench\Foundation\Application;
use Storyfeed\StoryfeedServiceProvider;
use Symfony\Component\Uid\Ulid;

/**
 * A fresh app on an empty in-memory SQLite database, with core's tables, as
 * the test suite boots one per test. Each docs read gets its own, so one
 * scene's activities never group with another's.
 *
 * ULIDs are counted, not random, from the frozen clock: a group's id and
 * cursor carry its activities' ULIDs, and a generated file must come out
 * the same on every run.
 */
final class Harness
{
    public static function boot(): \Illuminate\Foundation\Application
    {
        // Model listeners belong to the app that booted them.
        Model::clearBootedModels();
        Facade::clearResolvedInstances();

        $app = Application::create(
            basePath: Application::applicationBasePath(),
            options: ['extra' => ['dont-discover' => ['*']]],
        );

        $app['config']->set('database.default', 'docs');
        $app['config']->set('database.connections.docs', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        // No feed file: the world's wording is registered by World. A verb the
        // world gives no wording reads with no headline, as it would in an app
        // in production, rather than refusing to publish.
        $app['config']->set('storyfeed.definitions', false);
        $app['config']->set('storyfeed.verbs.strict', false);
        $app['config']->set('storyfeed.grammar.strict', false);

        $app->register(StoryfeedServiceProvider::class);

        $next = 0;
        Str::createUlidsUsing(function () use (&$next) {
            return new Ulid(self::base32(Carbon::now()->getTimestampMs(), 10).self::base32(++$next, 16));
        });

        // Published migrations are timestamped in order; the stubs are not,
        // so create_* stubs run before any alter-style stub (as TestCase does).
        $stubs = glob(__DIR__.'/../../../database/migrations/*.stub') ?: [];
        usort($stubs, fn ($a, $b) => str_starts_with(basename($b), 'create_') <=> str_starts_with(basename($a), 'create_'));

        foreach ($stubs as $stub) {
            (include $stub)->up();
        }

        return $app;
    }

    /** A number in Crockford's base 32, as a ULID writes it, padded to a width. */
    protected static function base32(int $number, int $width): string
    {
        $digits = '';

        do {
            $digits = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'[$number % 32].$digits;
            $number = intdiv($number, 32);
        } while ($number > 0);

        return str_pad($digits, $width, '0', STR_PAD_LEFT);
    }
}
