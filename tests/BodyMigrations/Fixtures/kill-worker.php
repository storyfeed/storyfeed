<?php

use Illuminate\Contracts\Console\Kernel;
use Storyfeed\BodyMigrations\BodyMigrator;
use Storyfeed\BodyMigrations\RewriteBodies;
use Storyfeed\StoryfeedServiceProvider;
use Storyfeed\Tests\BodyMigrations\Fixtures\MatureFeed;

// Isolated worker for the actual SIGKILL/resume proof. All files live in this worktree.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->register(StoryfeedServiceProvider::class);
config()->set('database.default', 'body_kill');
config()->set('database.connections.body_kill', ['driver' => 'sqlite', 'database' => $argv[1], 'prefix' => '', 'foreign_key_constraints' => true]);
config()->set('cache.default', 'array');
app(BodyMigrator::class)->path($argv[2]);
MatureFeed::register(6);
app(RewriteBodies::class)->run(500, function (int $chunks): void {
    if ($chunks === 5) {
        posix_kill(getmypid(), SIGKILL);
    }
});
