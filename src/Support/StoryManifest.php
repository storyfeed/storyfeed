<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Foundation\Application;
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
     * @return array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}|null
     */
    public function read(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $manifest = require $this->path();

        return $this->valid($manifest) ? $manifest : null;
    }

    /**
     * @param  array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}  $compiled
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
     * @param  array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>}  $compiled
     * @return array<string, array<string, string>>
     */
    protected function serializable(array $compiled): array
    {
        $compiled['verbs'] = array_map(
            fn (mixed $type) => $type instanceof \BackedEnum ? (string) $type->value : (string) $type,
            $compiled['verbs'],
        );

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
