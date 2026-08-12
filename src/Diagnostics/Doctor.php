<?php

namespace Storyfeed\Diagnostics;

use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\StoryfeedManager;
use Throwable;

/**
 * Runs the checks and collects a Report.
 *
 * The check list is a property rather than a hardcoded sequence so an app can
 * add its own (`Storyfeed::checks([...])`) — the same registry shape as every
 * other extension point here.
 *
 * A check that throws is reported as a finding rather than taking the run down.
 * That rule is paid for: a check once queried the column whose absence it
 * existed to report, so a real schema drift crashed the diagnosis instead of
 * naming it. A diagnostic that dies on the condition it diagnoses is worse
 * than none.
 */
class Doctor
{
    /** @var list<class-string<DiagnosticCheck>> */
    public const DEFAULT_CHECKS = [
        Checks\Tables::class,
        Checks\Columns::class,
        Checks\Coverage::class,
        Checks\AggregateCoverage::class,
        Checks\AggregateTokens::class,
        Checks\VerbDrift::class,
        Checks\HashLengths::class,
        Checks\SnapshotShapes::class,
        Checks\Backlog::class,
        Checks\FeedStale::class,
        Checks\Parties::class,
    ];

    /** @param list<DiagnosticCheck> $checks */
    public function __construct(
        protected array $checks,
    ) {}

    /** @param list<string> $only check names to run; empty runs all */
    public function run(StoryfeedManager $storyfeed, array $only = []): Report
    {
        $findings = [];

        foreach ($this->checks as $check) {
            if ($only !== [] && ! in_array($check->name(), $only, true)) {
                continue;
            }

            try {
                foreach ($check->run($storyfeed) as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $e) {
                $findings[] = Finding::error(
                    'doctor.check_failed',
                    "Check `{$check->name()}` threw ".$e::class.': '.$e->getMessage()
                    .' — the other checks still ran, but this one told you nothing.',
                    ['check' => $check->name(), 'exception' => $e::class],
                );
            }
        }

        return new Report($findings);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (DiagnosticCheck $check) => $check->name(), $this->checks);
    }
}
