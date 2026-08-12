<?php

namespace Storyfeed\Contracts;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * One health check. Returns findings; never writes, never prints.
 *
 * Two rules learned from the version of this that lived inside the command:
 *
 * 1. **A check must not crash on the condition it exists to diagnose.**
 *    `SnapshotShapes` once queried the very column whose absence it was meant
 *    to report, so a real drift took the whole doctor down mid-diagnosis. Each
 *    check guards its own preconditions and returns `[]` rather than throwing.
 * 2. **Silence must mean "checked and clean", never "skipped".** A check that
 *    quietly opts out is indistinguishable from a healthy system — which is
 *    exactly how a missing `repeat.archive` key survived four rounds of audits.
 *    Where a check genuinely cannot run, say so as an Info finding.
 */
interface DiagnosticCheck
{
    /** Stable, greppable identifier — also the `--only=` filter value. */
    public function name(): string;

    /** @return iterable<int, Finding> */
    public function run(StoryfeedManager $storyfeed): iterable;
}
