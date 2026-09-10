<?php

namespace Storyfeed\Diagnostics;

use Composer\InstalledVersions;
use Throwable;

/**
 * What produced this report, as data.
 *
 * Both consumers float `dev-main`, so there is no version to cite and a bug
 * report against the package is unfalsifiable without a commit. Twice in one
 * day a measurement here was correct about its own bytes and wrong as a fact
 * about the dependency — a stale `vendor/` read honestly — which cost a true
 * bug report, a wrong retraction of it, and two rulings made on a false
 * premise. Neither reading carried the commit it came from.
 *
 * So doctor stamps its own output. Resolved from the INSTALL rather than from
 * git: the question is which bytes ran, not which branch is checked out, and
 * those differ exactly when it matters.
 *
 * AN ABSENT REFERENCE IS SAID OUT LOUD. `Composer\InstalledVersions` may be
 * missing (a classmap-only autoloader), a package may not be installed through
 * Composer at all, and the ROOT package's reference is stamped at install time
 * rather than tracking HEAD — citing that as "the commit doctor ran" is the
 * same class of confident wrongness this exists to end. In every one of those
 * cases the reference is null and the output says so. A missing SHA costs a
 * round trip; a wrong one costs a retraction.
 */
final class Installed
{
    /**
     * @param  array<string, array{version: string|null, reference: string|null, root: bool}>  $packages
     */
    public function __construct(
        public readonly array $packages = [],
    ) {}

    /** Every installed `storyfeed/*` package — the plugin and the renderers included. */
    public static function resolve(): self
    {
        if (! class_exists(InstalledVersions::class)) {
            return new self;
        }

        $root = self::rootName();
        $packages = [];

        try {
            $names = InstalledVersions::getInstalledPackages();
        } catch (Throwable) {
            return new self;
        }

        foreach ($names as $name) {
            if (! str_starts_with($name, 'storyfeed/')) {
                continue;
            }

            $isRoot = $name === $root;

            try {
                $packages[$name] = [
                    'version' => InstalledVersions::getPrettyVersion($name),
                    // Deliberately dropped for the root package: see the class docblock.
                    'reference' => $isRoot ? null : InstalledVersions::getReference($name),
                    'root' => $isRoot,
                ];
            } catch (Throwable) {
                $packages[$name] = ['version' => null, 'reference' => null, 'root' => $isRoot];
            }
        }

        ksort($packages);

        return new self($packages);
    }

    /**
     * The root package's own name, when Composer can say it. Its reference is
     * stamped at install time and does not track HEAD, so knowing which entry
     * is the root is what keeps a stale SHA out of the stamp.
     */
    protected static function rootName(): ?string
    {
        try {
            return InstalledVersions::getRootPackage()['name'];
        } catch (Throwable) {
            return null;
        }
    }

    public function isEmpty(): bool
    {
        return $this->packages === [];
    }

    /**
     * One line, paste-ready into an issue. Says "unknown" where it is unknown.
     */
    public function line(): string
    {
        if ($this->isEmpty()) {
            return 'Installed: no `storyfeed/*` package could be resolved through Composer, '
                .'so this report carries no commit — cite yours by hand in any bug report.';
        }

        $parts = [];

        foreach ($this->packages as $name => $package) {
            $parts[] = $name.' '.self::describe($package);
        }

        return 'Installed: '.implode(', ', $parts);
    }

    /** @param array{version: string|null, reference: string|null, root: bool} $package */
    protected static function describe(array $package): string
    {
        $version = $package['version'] ?? 'version unknown';

        if ($package['reference'] !== null) {
            return $version.'@'.substr($package['reference'], 0, 12);
        }

        return $version.($package['root']
            ? ' (root package — no installed commit to cite)'
            : ' (commit unknown)');
    }

    /** @return array<string, array{version: string|null, reference: string|null, root: bool}> */
    public function toArray(): array
    {
        return $this->packages;
    }
}
