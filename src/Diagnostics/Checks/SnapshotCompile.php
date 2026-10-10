<?php

namespace Storyfeed\Diagnostics\Checks;

use InvalidArgumentException;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SnapshotCompiler;

/**
 * `STORYFEED_SNAPSHOTS=sync` is for local development, like
 * `QUEUE_CONNECTION=sync`. Copied into production, every feed read stats the
 * model files, and a deploy that touches them recompiles inside a request.
 */
class SnapshotCompile extends Check
{
    public function name(): string
    {
        return 'snapshots';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        try {
            $mode = app(SnapshotCompiler::class)->mode();
        } catch (InvalidArgumentException $e) {
            yield Finding::error('snapshots.mode', $e->getMessage().' Feed reads throw until it is.');

            return;
        }

        if ($mode === 'sync' && ! app()->environment(['local', 'testing'])) {
            $environment = (string) app()->environment();

            yield Finding::warning(
                'snapshots.sync',
                "Snapshots compile in `sync` mode in `{$environment}`. Feed reads check the model files for "
                .'changes and recompile inside the request. `STORYFEED_SNAPSHOTS=sync` belongs in your local '
                .'.env; production uses `cached`, which `php artisan optimize` compiles.',
                ['environment' => $environment],
            );
        }
    }
}
