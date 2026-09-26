<?php

namespace Storyfeed\BodyMigrations;

use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use Storyfeed\Contracts\FeedBody;

final class BodyMigrator
{
    /** @var list<string> */
    private array $paths = [];

    /** @var array<class-string<FeedBody>, list<array{name: string, hash: string, blueprint: BodyBlueprint}>>|null */
    private ?array $steps = null;

    /** @var array<class-string<FeedBody>, BodyBlueprint>|null */
    private ?array $collecting = null;

    public function path(string $path): void
    {
        $this->paths[] = $path;
        $this->steps = null;
    }

    /** @param class-string<FeedBody> $body
     * @param  Closure(BodyBlueprint): void  $callback
     */
    public function table(string $body, Closure $callback): void
    {
        if ($this->collecting === null) {
            throw new LogicException('Body::table() belongs inside a body migration.');
        }
        if (isset($this->collecting[$body])) {
            throw new LogicException('Declare a body only once in each migration.');
        }
        $blueprint = new BodyBlueprint;
        $callback($blueprint);
        $this->collecting[$body] = $blueprint;
    }

    /** @param class-string<FeedBody> $body */
    public function version(string $body): int
    {
        $this->load();

        return 1 + count($this->steps[$body] ?? []);
    }

    /** @param class-string<FeedBody> $body
     * @return list<array{name: string, hash: string}>
     */
    public function history(string $body): array
    {
        $this->load();

        return array_map(fn (array $step): array => ['name' => $step['name'], 'hash' => $step['hash']], $this->steps[$body] ?? []);
    }

    /** @param class-string<FeedBody> $body
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upgrade(string $body, array $payload, int $from): array
    {
        $this->load();
        foreach (array_slice($this->steps[$body] ?? [], max(0, $from - 1)) as $step) {
            $payload = $step['blueprint']->apply($payload);
        }

        return $payload;
    }

    /** @return array<class-string<FeedBody>, BodyBlueprint> */
    private function collected(): array
    {
        return $this->collecting ?? [];
    }

    private function load(): void
    {
        if ($this->steps !== null) {
            return;
        }
        $steps = [];
        $core = dirname(__DIR__, 2).'/database/body-migrations';
        // Laravel Migrator::getMigrationFiles: timestamped names, keyed and sorted by filename.
        $paths = array_unique([$core, ...$this->paths, database_path('body-migrations')]);
        $names = [];
        foreach ($paths as $path) {
            foreach (app(Migrator::class)->getMigrationFiles($path) as $name => $file) {
                if (isset($names[$name]) && $names[$name] !== $file) {
                    throw new LogicException("Duplicate body migration {$name}; reconcile branches before release.");
                }
                $names[$name] = $file;
            }
        }
        $files = app(Migrator::class)->getMigrationFiles($paths);
        foreach ($files as $name => $file) {
            $migration = (new Filesystem)->getRequire($file);
            if (! $migration instanceof BodyMigration) {
                throw new LogicException("{$name} must return an anonymous BodyMigration.");
            }
            $this->collecting = [];
            try {
                $migration->up();
                foreach ($this->collected() as $body => $blueprint) {
                    if (str_starts_with($body::bodyType(), 'Storyfeed/') && dirname($file) !== $core) {
                        throw new LogicException('Only Storyfeed may migrate '.$body::bodyType().'.');
                    }
                    $steps[$body][] = ['name' => $name, 'hash' => hash_file('sha256', $file), 'blueprint' => $blueprint];
                }
            } finally {
                $this->collecting = null;
            }
        }
        $this->steps = $steps;
    }
}
