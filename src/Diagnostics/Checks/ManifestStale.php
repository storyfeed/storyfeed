<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\StoryManifest;

/**
 * Does the cached manifest still match what the stories compile to?
 *
 * A cached manifest is a NEW INSTANCE of the silent-drift class that already
 * cost this package a production outage: edit a Story, forget `storyfeed:cache`,
 * and the feed serves the old headline while deploys, migrations and tests all
 * stay green.
 *
 * The instrument follows the rule the snapshot shape signatures established —
 * FINGERPRINT THE OUTPUT, NOT THE SOURCE. File mtimes and content hashes are
 * the wrong tool here: whitespace invalidates them, and a changed collaborator
 * the story reads does not touch them at all.
 *
 * Recompiling in memory to compare is affordable precisely BECAUSE doctor is a
 * deliberate command — the same trade SnapshotShapes already makes. The runtime
 * path trusts the manifest with zero validation cost, exactly as Laravel trusts
 * a config cache.
 */
class ManifestStale extends Check
{
    public function __construct(
        protected StoryManifest $manifest,
    ) {}

    public function name(): string
    {
        return 'manifest';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $cached = $this->manifest->read();

        if ($cached === null) {
            return;
        }

        try {
            $fresh = $storyfeed->compiledStories();
        } catch (StoryMisconfigured $e) {
            yield Finding::error(
                'manifest.uncompilable',
                'The cached manifest is in use but the registered stories no longer compile: '
                .$e->getMessage().' Until this is fixed, the feed is serving CACHED output for stories '
                .'whose source is broken.',
            );

            return;
        }

        $drifted = [];

        foreach (['grammar', 'aggregateGrammar', 'icons', 'verbs'] as $registry) {
            // No `?? []` — read() validates the shape, so a missing key would
            // be a bug to surface, not a case to paper over.
            $before = $this->normalize($cached[$registry]);
            $after = $this->normalize($fresh[$registry]);

            foreach (array_keys($before + $after) as $key) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $drifted[] = "{$registry}[{$key}]";
                }
            }
        }

        if ($drifted === []) {
            return;
        }

        yield Finding::warning(
            'manifest.stale',
            'The cached story manifest no longer matches the stories on disk, so the feed is serving '
            .'the OLD text for: '.implode(', ', array_slice($drifted, 0, 10))
            .(count($drifted) > 10 ? ' (+'.(count($drifted) - 10).' more)' : '')
            .'. Run `php artisan storyfeed:cache` (or `php artisan optimize`).',
            ['drifted' => count($drifted)],
        );
    }

    /**
     * The manifest stores enum values as strings (var_export cannot round-trip
     * an enum case), so compare on that footing rather than reporting every
     * verb as drifted.
     *
     * @param  array<string, mixed>  $registry
     * @return array<string, string>
     */
    protected function normalize(array $registry): array
    {
        return array_map(
            fn (mixed $value) => $value instanceof \BackedEnum ? (string) $value->value : (string) $value,
            array_filter($registry, fn (mixed $value) => ! $value instanceof \Closure),
        );
    }
}
