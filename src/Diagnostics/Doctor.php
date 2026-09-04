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
        Checks\Recording::class,
        Checks\Coverage::class,
        Checks\SingularTokens::class,
        Checks\AggregateCoverage::class,
        Checks\AggregateTokens::class,
        Checks\VerbDrift::class,
        Checks\FeedCoverage::class,
        Checks\HashLengths::class,
        Checks\SnapshotShapes::class,
        Checks\Backlog::class,
        Checks\Ungrouped::class,
        Checks\FeedStale::class,
        Checks\ManifestStale::class,
        Checks\UnwiredSurface::class,
        Checks\Parties::class,
        Checks\Participants::class,
    ];

    /** @param list<DiagnosticCheck> $checks */
    public function __construct(
        protected array $checks,
    ) {}

    /** @param list<string> $only check names to run; empty runs all */
    public function run(StoryfeedManager $storyfeed, array $only = []): Report
    {
        $findings = $this->unknownNames($only);

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

    /**
     * A name in `--only=` that matches no registered check.
     *
     * WHY A FINDING AND NOT A THROW. `--only=grammer` used to run nothing and
     * report nothing, which reads as a clean bill of health: an app gating CI
     * on `--only=` gets a green build from a check that never ran — the same
     * vacuous pass the testing helpers refuse by design, sitting in the
     * diagnostic layer itself. But throwing is the wrong instrument here. The
     * registries this package guards are called once at boot with literals;
     * doctor() takes RUNTIME input that may already be whatever an operator
     * typed, and killing a scheduled health check is a worse bug than the one
     * being closed. That is the rule Doctor already lives by one level down: a
     * check that throws becomes a finding rather than taking the run with it.
     *
     * WARNING, not Info, and that is the whole fix. An Info leaves the build
     * green and the vacuous pass survives with a note attached — worse than
     * before, because now there is a line in the report that LOOKS like the
     * system noticed. This must trip `--fail-on=warning`.
     *
     * The valid names are listed, the way the axis recipe error lists its known
     * tokens: a typo is most cheaply fixed by being shown the right spelling.
     *
     * @param  list<string>  $only
     * @return list<Finding>
     */
    protected function unknownNames(array $only): array
    {
        $known = $this->names();

        return array_values(array_map(
            fn (string $name) => Finding::warning(
                'doctor.unknown_check',
                "No check is named `{$name}`, so `--only={$name}` ran nothing for it — a report that is "
                .'empty because nothing ran looks exactly like a clean one. Available checks: '
                .implode(', ', $known).'.',
                ['requested' => $name, 'available' => implode(', ', $known)],
            ),
            array_filter($only, fn (string $name) => ! in_array($name, $known, true)),
        ));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (DiagnosticCheck $check) => $check->name(), $this->checks);
    }
}
