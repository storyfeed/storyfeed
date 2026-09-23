<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Storyfeed\Support\DefinitionsFile;

/**
 * Install Storyfeed: publish the config and migrations, create
 * `routes/feed.php`, and offer to migrate. The analogue of
 * `install:broadcasting` creating `routes/channels.php`.
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
        {--without-migrations : Publish no migrations}';

    protected $description = 'Publish Storyfeed\'s config and migrations, and create routes/feed.php';

    public function handle(DefinitionsFile $file, Filesystem $files): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'storyfeed-config']);
        $this->components->info('Published config/storyfeed.php.');

        if (! $this->option('without-migrations')) {
            $this->callSilently('vendor:publish', ['--tag' => 'storyfeed-migrations']);
            $this->components->info('Published the migrations.');
        }

        $this->createDefinitionsFile($file, $files);

        if (! $this->option('without-migrations')
            && $this->input->isInteractive()
            && $this->confirm('Run the migrations now?')) {
            $this->call('migrate');
        }

        return self::SUCCESS;
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
