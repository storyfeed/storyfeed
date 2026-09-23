<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Storyfeed\Actions\CompileStories;
use Storyfeed\StoryfeedManager;

/**
 * The compiled-story manifest: `bootstrap/cache/storyfeed.php`.
 *
 * Owns the path, the read and the write so the two commands, the provider and
 * the staleness check cannot disagree about any of them.
 *
 * WHAT IT HOLDS — only the story-compiled arrays. Hand-written registrations
 * stay boot-time and keep winning: they may legally contain closures (closure
 * grammar is a documented feature) and Axis objects can hold closure recipes,
 * so neither is serializable. Leaving the precedence rule untouched is what
 * makes this safe to bolt on rather than a redesign.
 *
 * THE RISK, NAMED UP FRONT. A cached manifest is a new instance of the
 * silent-drift class that cost this package a production outage: edit a Story,
 * forget to recompile, and the feed serves the old headline while every signal
 * stays green. Mitigations, in order:
 *
 *   1. It is NEVER written implicitly. Only `storyfeed:cache` writes it, so a
 *      developer who has not opted in has nothing stale to fight.
 *   2. `storyfeed:doctor` recompiles in memory and reports the drift
 *      (Diagnostics\Checks\ManifestStale). Fingerprinting the OUTPUT rather
 *      than the source files is deliberate — mtimes and content hashes are the
 *      wrong instrument, since whitespace invalidates them and a changed
 *      collaborator the story reads does not.
 *   3. A compile that throws writes nothing, so a broken Story cannot leave a
 *      half-manifest that boots.
 *
 * A closure headline from the Story facade can't be var_export'ed, so a
 * compile holding one is refused by key (closures()) rather than written.
 * FeedHeadline and FeedNoun values export themselves (`__set_state`).
 *
 * @phpstan-import-type Compiled from CompileStories
 */
class StoryManifest
{
    public function __construct(
        protected Application $app,
    ) {}

    public function path(): string
    {
        return $this->app->bootstrapPath('cache/storyfeed.php');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return Compiled|null
     */
    public function read(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $manifest = require $this->path();

        if (! $this->valid($manifest)) {
            return null;
        }

        // Written before glyph intents existed (2026-09-09), or before the
        // Story facade's registries (2026-09-23): a complete description of
        // stories that carried none, not a truncated one.
        foreach (CompileStories::REGISTRIES as $registry) {
            $manifest[$registry] ??= [];
        }

        /** @var Compiled $manifest */
        return $manifest;
    }

    /**
     * The `registry[key]` entries a compile holds as closures, which a
     * manifest can't store. storyfeed:cache refuses to write while any exist.
     *
     * @param  Compiled  $compiled
     * @return list<string>
     */
    public function closures(array $compiled): array
    {
        $found = [];

        foreach (CompileStories::REGISTRIES as $registry) {
            foreach ($compiled[$registry] as $key => $value) {
                if ($value instanceof Closure) {
                    $found[] = "{$registry}[{$key}]";
                }
            }
        }

        return $found;
    }

    /**
     * @param  Compiled  $compiled
     */
    public function write(array $compiled): string
    {
        $path = $this->path();

        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            '<?php return '.var_export($this->serializable($compiled), true).';'.PHP_EOL,
        );

        return $path;
    }

    public function delete(): bool
    {
        return $this->exists() && unlink($this->path());
    }

    /**
     * Apply the manifest to the manager, if there is one.
     */
    public function apply(StoryfeedManager $storyfeed): bool
    {
        $manifest = $this->read();

        if ($manifest === null) {
            return false;
        }

        $storyfeed->useCompiledStories($manifest);

        return true;
    }

    /**
     * Enum values cannot be var_export'ed back into a literal that survives a
     * `require`, so the verb registry's ActivityType cases are stored as their
     * string values. The registry accepts raw strings by design — extension
     * types like 'sf:Frobnicate' must round-trip — so nothing is lost.
     *
     * @param  Compiled  $compiled
     * @return array<string, array<string, mixed>>
     */
    protected function serializable(array $compiled): array
    {
        foreach (['verbs', 'objectTypes'] as $registry) {
            $compiled[$registry] = array_map(
                fn (mixed $type) => $type instanceof \BackedEnum ? (string) $type->value : (string) $type,
                $compiled[$registry],
            );
        }

        return $compiled;
    }

    /**
     * A manifest from an older package version, or a truncated write, must be
     * ignored rather than half-applied.
     */
    protected function valid(mixed $manifest): bool
    {
        if (! is_array($manifest)) {
            return false;
        }

        foreach (['grammar', 'aggregateGrammar', 'icons', 'verbs'] as $registry) {
            if (! isset($manifest[$registry]) || ! is_array($manifest[$registry])) {
                return false;
            }
        }

        return true;
    }
}
