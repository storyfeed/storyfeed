<?php

namespace Storyfeed\Diagnostics;

use Storyfeed\StoryfeedManager;
use Storyfeed\Support\VerbFilter;
use Throwable;

/**
 * Which axes the REGISTERED feeds can ever render, read from the mode each
 * feed declared.
 *
 * WHY THIS EXISTS. A coverage check that walks the groupings table knows what
 * the database clustered; it knows nothing about what the reader is about to
 * do. A `->live()` dashboard renders `repeat` and authored `composite` group
 * nodes and NOTHING else, so aggregate grammar for `object.upload` on such an
 * app is a template that cannot fire — doctor asking for it is asking for
 * work with no effect. This is the seam that lets a check tell the two apart.
 *
 * THE RULE IS READ OFF `FeedBuilder::winning()`, not reasoned about:
 *
 *   log      shouldGroup() is false — logPage() renders no group node at all,
 *            so no axis is readable.
 *   live     `bucket = 'repeat'` OR (`bucket = 'composite'` AND winner) —
 *            two axes, and the winner column is never consulted for repeat.
 *   summary  winner rows on ANY bucket, plus the repeat fallback — every
 *            registered axis is readable.
 *
 * THE HONEST LIMITS, all of which push the SAME way — toward reporting a pair
 * rather than excusing it:
 *
 * - No registered feeds means no answer, never an all-clear. `isConclusive()`
 *   is false and the caller must say so out loud.
 * - A feed that throws while being inspected is opaque, not absent: one
 *   unreadable feed makes "nothing reads this" unsayable for the whole run.
 * - A mode declared in `define()` is a presentation default and any call site
 *   may override it (docs/feeds.md). So "unreachable" means unreachable as
 *   DECLARED — worth saying, never worth silencing a pair over.
 * - `query()` can narrow a feed invisibly, so a feed counted as a reader may
 *   in fact never show the verb. That over-reports readers, which keeps the
 *   plain warning — the safe direction.
 */
final class Reachability
{
    /**
     * @param  array<string, array{mode: string, axes: list<string>|null, filter: VerbFilter}>  $feeds
     *                                                                                                  `axes` null means every axis — a summary surface reads winners on any bucket.
     * @param  array<string, string>  $opaque  feed name => the exception class that hid its mode
     * @param  array<string, string>  $sources  feed name => where it was declared, opaque ones included
     */
    private function __construct(
        private readonly array $feeds,
        private readonly array $opaque,
        private readonly array $sources,
    ) {}

    public static function of(StoryfeedManager $storyfeed): self
    {
        $feeds = [];
        $opaque = [];
        $sources = [];

        foreach ($storyfeed->registeredFeeds() as $name => $definition) {
            // Kept outside the try: a feed that will not inspect still has a
            // file and a line, and that is exactly the feed a finding needs to
            // point at.
            $sources[$name] = $definition->source;

            // Per-feed, as FeedCoverage does it: one broken app closure must
            // not cost the reachability answer for every other feed. And
            // inspect() rather than build(), because a subject feed cannot be
            // constructed by tooling — define() is the half that is readable
            // without a subject, and mode is declared there.
            try {
                $builder = $definition->inspect();
                $mode = $builder->declaredMode();

                $feeds[$name] = [
                    'mode' => $mode,
                    'axes' => self::axesFor($mode),
                    'filter' => $builder->declaredVerbFilter(),
                ];
            } catch (Throwable $e) {
                $opaque[$name] = $e::class;
            }
        }

        return new self($feeds, $opaque, $sources);
    }

    /**
     * The axes a mode can surface. Null is "all of them".
     *
     * `storyfeed.grouping.strategy` being NullStrategy would silence groups
     * for every mode, but that case cannot reach a caller of this class: with
     * no strategy nothing stamps groupings, so a clustered pair never exists
     * to ask about.
     *
     * @return list<string>|null
     */
    private static function axesFor(string $mode): ?array
    {
        return match ($mode) {
            'log' => [],
            'live' => ['repeat', 'composite'],
            default => null,
        };
    }

    /** Whether any feed is registered at all. */
    public function hasFeeds(): bool
    {
        return $this->feeds !== [] || $this->opaque !== [];
    }

    /**
     * Whether "no registered feed reads this" is a statement this object is
     * entitled to make. An absence of information is not evidence of absence,
     * so both an empty registry and a single un-inspectable feed withdraw it.
     */
    public function isConclusive(): bool
    {
        return $this->feeds !== [] && $this->opaque === [];
    }

    /**
     * The registered feeds whose declared mode and declared verb filter would
     * put this (axis, verb) pair on screen.
     *
     * @return list<string>
     */
    public function readers(string $axis, string $verb): array
    {
        $readers = [];

        foreach ($this->feeds as $name => $feed) {
            if ($feed['axes'] !== null && ! in_array($axis, $feed['axes'], true)) {
                continue;
            }

            if (! $feed['filter']->admits($verb)) {
                continue;
            }

            $readers[] = $name;
        }

        return $readers;
    }

    /** The distinct modes the registry declares, sorted — for a finding to quote. */
    public function modes(): string
    {
        $modes = array_values(array_unique(array_column($this->feeds, 'mode')));

        sort($modes);

        return implode(', ', $modes);
    }

    /** @return array<string, string> feed name => the exception class that hid its mode */
    public function opaque(): array
    {
        return $this->opaque;
    }

    public function source(string $feed): string
    {
        return $this->sources[$feed] ?? 'an unknown location';
    }
}
