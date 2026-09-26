<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Storyfeed\BodyMigrations\BodyMigrationCreator;
use Storyfeed\Contracts\FeedBody;

final class BodyMigrationMakeCommand extends Command
{
    protected $signature = 'make:body-migration {body : Fully qualified body class} {name : Migration name}';

    protected $description = 'Create a timestamped body migration and a snapshot test';

    public function handle(Filesystem $files): int
    {
        $body = ltrim($this->argument('body'), '\\');
        if (! is_subclass_of($body, FeedBody::class) || str_starts_with($body::bodyType(), 'Storyfeed/')) {
            $this->error('Choose an application-owned FeedBody class.');

            return self::FAILURE;
        }
        $name = Str::snake(trim($this->argument('name')));
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
            $this->error('Use a migration name containing letters, numbers and underscores.');

            return self::FAILURE;
        }
        $creator = new BodyMigrationCreator($files, base_path('stubs'));
        $path = $creator->create($name, database_path('body-migrations'), $body);
        $files->put($path, str_replace('{{ body }}', class_basename($body), $files->get($path)));
        $test = base_path('tests/Feature/'.class_basename($body).'BodyMigrationTest.php');
        if (! $files->exists($test)) {
            $files->ensureDirectoryExists(dirname($test));
            $files->put($test, str_replace(['{{ class }}', '{{ body }}'], [$body, class_basename($body)], $files->get(dirname(__DIR__, 2).'/stubs/body-migration-test.stub')));
        }
        $this->info($path);
        $this->info("Complete the examples and released snapshot in {$test}.");

        return self::SUCCESS;
    }
}
