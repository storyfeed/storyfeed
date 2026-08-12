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
 */
enum Severity: string
{
    /** A fact worth printing. Never counts toward the finding total. */
    case Info = 'info';

    /** Something is wrong or will silently degrade. */
    case Warning = 'warning';

    /** Broken now — writes throw, or the schema cannot serve the code. */
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
