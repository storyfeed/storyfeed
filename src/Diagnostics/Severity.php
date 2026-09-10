<?php

namespace Storyfeed\Diagnostics;

/**
 * How much a finding matters.
 *
 * Deliberately three levels, not five. `Info` exists because several checks
 * report facts that are not problems (a party's activity count), and folding
 * those into warnings is what makes a report people stop reading — the
 * failure mode the Newsroom named: "a coverage tool that silently skips a
 * category is indistinguishable from a healthy system", and its twin, a tool
 * that cries wolf until nobody looks.
 *
 * THE AXIS IS LIVE VS LATENT, NOT STRUCTURAL VS SEMANTIC (2026-09-10). For
 * its first year `Error` meant "the schema or the runner is broken" and every
 * grammar, role and entity finding was a `Warning` — so `--fail-on=error`
 * could not see a feed rendering a broken sentence, and `--fail-on=warning`
 * fired on verbs declared and never recorded. A consumer proved the first
 * half by unregistering an entire enum and exiting 0, and a sentence that
 * was wrong sat on their owner's dashboard for weeks with no severity to
 * catch it. Every finding is now classified on one question: does it
 * describe something CURRENTLY WRONG on a surface that EXISTS?
 */
enum Severity: string
{
    /**
     * A fact worth printing, or a gap that is latent by construction —
     * declared and never recorded, clustering on an axis no feed reads.
     * Nothing renders wrong today. Never counts toward the finding total.
     */
    case Info = 'info';

    /** Real, not yet biting: it will silently degrade, or degrades the way the read path was designed to. */
    case Warning = 'warning';

    /**
     * Wrong today, on a surface that exists — a broken schema, a check that
     * could not answer, or real rows rendering wrong right now. The floor a
     * consumer gates CI on from day one.
     */
    case Error = 'error';

    /** Info is reportage; warnings and errors are findings. */
    public function isFinding(): bool
    {
        return $this !== self::Info;
    }

    public function atLeast(self $floor): bool
    {
        return $this->weight() >= $floor->weight();
    }

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }
}
