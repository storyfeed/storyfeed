<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Storyfeed\Stories\DefinitionsFile;

/**
 * Install Storyfeed: publish the config and migrations, create
 * `routes/feed.php`, set `STORYFEED_SNAPSHOTS=sync` in .env, and offer to
 * migrate. The analogue of
 * `install:broadcasting` creating `routes/channels.php`.
 *
 * `--without-storage` is for an app that composes and renders feeds from its
 * own data: no migrations, no snapshot setting, and the line that declares
 * the mode, `Storyfeed::withoutStorage()`, to add to a service provider.
 *
 * IT NEVER OVERWRITES the definitions file. An app may already use
 * `routes/feed.php` for its own RSS or feed-page routes, so an existing file
 * is left alone, and the command says how to point Storyfeed elsewhere.
 *
 * The stub has no live code: the Quickstart's Order doesn't exist in a fresh
 * app, and a live example would define a verb nobody records.
 */
class InstallCommand extends Command
{
    protected $signature = 'storyfeed:install
        {--without-migrations : Publish no migrations}
        {--without-storage : Compose and render only, with no tables or recording}';

    protected $description = 'Publish Storyfeed\'s config and migrations, and create routes/feed.php';

    public function handle(DefinitionsFile $file, Filesystem $files): int
    {
        $storage = ! $this->option('without-storage');

        $this->callSilently('vendor:publish', ['--tag' => 'storyfeed-config']);
        $this->components->info('Published config/storyfeed.php.');

        if ($storage && ! $this->option('without-migrations')) {
            $this->callSilently('vendor:publish', ['--tag' => 'storyfeed-migrations']);
            $this->components->info('Published the migrations.');
        }

        $this->createDefinitionsFile($file, $files);

        if (! $storage) {
            $this->components->info('Declare it in AppServiceProvider::boot(), so nothing is scheduled or recorded:');
            $this->line('  Storyfeed::withoutStorage();');

            return self::SUCCESS;
        }

        $this->writeEnvironment($files);

        if (! $this->option('without-migrations')
            && $this->input->isInteractive()
            && $this->confirm('Run the migrations now?')) {
            $this->call('migrate');
        }

        return self::SUCCESS;
    }

    /**
     * `STORYFEED_SNAPSHOTS=sync` in .env and commented in .env.example, as
     * Laravel ships `QUEUE_CONNECTION`. A line already there is left alone.
     */
    protected function writeEnvironment(Filesystem $files): void
    {
        $written = false;

        $env = $this->laravel->environmentFilePath();

        foreach ([$env => 'STORYFEED_SNAPSHOTS=sync', $env.'.example' => '# STORYFEED_SNAPSHOTS=sync'] as $path => $line) {

            if (! $files->exists($path)) {
                continue;
            }

            $contents = $files->get($path);

            if (preg_match('/^#?\s*STORYFEED_SNAPSHOTS=/m', $contents) === 1) {
                continue;
            }

            $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $separator = $contents === '' || str_ends_with($contents, "\n") ? '' : $eol;
            $files->append($path, $separator.$line.$eol);
            $written = $written || $path === $env;
        }

        if ($written) {
            $this->components->info('Set STORYFEED_SNAPSHOTS=sync in .env, so toFeed() changes show on reload.');
        }
    }

    protected function createDefinitionsFile(DefinitionsFile $file, Filesystem $files): void
    {
        $path = $file->path();

        if ($path === null) {
            $this->components->warn('storyfeed.definitions is off, so no definitions file was created.');

            return;
        }

        $relative = $file->relativePath();

        if ($files->exists($path)) {
            if (str_contains($files->get($path), 'Storyfeed\Facades\Story')) {
                $this->components->info("{$relative} already exists; left as it is.");

                return;
            }

            $this->components->warn("{$relative} already exists and isn't a Storyfeed file, so it was left alone.");
            $this->line('  Point Storyfeed at another file in config/storyfeed.php, for example:');
            $this->line("  'definitions' => base_path('routes/stories.php'),");
            $this->line('  then run php artisan storyfeed:install again.');

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->copy(__DIR__.'/../../stubs/definitions.stub', $path);

        $this->components->info("Created {$relative}. Define what each activity says there.");
    }
}
