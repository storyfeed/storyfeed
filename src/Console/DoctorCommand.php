<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Report;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\StoryfeedManager;

/**
 * Surfaces silent-fallback traps as explicit findings: verbs without grammar
 * or icons, unmapped AS2.0 verbs/aliases, schema drift, snapshot backlog, a
 * feed that has stopped being written to, and table state.
 *
 * This is a FORMATTER. The checks live in Storyfeed\Diagnostics and the run
 * returns a Report, so `Storyfeed::doctor()` serves an app that wants to render
 * feed health in its own UI — the first consumer resorted to
 * `Artisan::call('storyfeed:doctor')` and printing the raw output, which is a
 * fair review of the command having been the only API.
 *
 * `--stubs` is the one worth knowing about: it prints the registrations the
 * findings imply, ready to paste. The authoring loop consumers arrive at —
 * *register the axis, run real traffic, run doctor, author exactly the keys it
 * names* — ends in manual transcription otherwise. Every emitted token comes
 * from the axis's compiled recipe, so a pasted snippet cannot reference a token
 * the axis fails to pin.
 */
class DoctorCommand extends Command
{
    protected $signature = 'storyfeed:doctor
        {--json : Emit the report as JSON}
        {--stubs : Print only the registrations the findings imply}
        {--only=* : Limit to named checks (see --list)}
        {--list : List the available check names}
        {--fail-on= : Exit non-zero when findings reach this severity (warning|error)}';

    protected $description = 'Audit grammar/icon/mapping coverage and feed health';

    public function handle(StoryfeedManager $storyfeed): int
    {
        if ($this->option('list')) {
            foreach ($this->checkNames($storyfeed) as $name) {
                $this->line($name);
            }

            return self::SUCCESS;
        }

        /** @var list<string> $only */
        $only = array_filter((array) $this->option('only'));

        $report = $storyfeed->doctor($only);

        match (true) {
            (bool) $this->option('json') => $this->renderJson($report),
            (bool) $this->option('stubs') => $this->renderStubs($report),
            default => $this->renderText($report),
        };

        return $this->exitCode($report);
    }

    protected function renderText(Report $report): void
    {
        foreach ($report->all() as $finding) {
            match ($finding->severity) {
                Severity::Error => $this->error($finding->message),
                Severity::Warning => $this->warn($finding->message),
                Severity::Info => $this->line($finding->message),
            };
        }

        if ($report->isHealthy()) {
            $this->info('Storyfeed looks healthy.');

            return;
        }

        $this->warn("{$report->count()} finding(s) — see above.");

        if ($report->fixes()->isNotEmpty()) {
            $this->line('Run with --stubs to print the registrations these imply.');
        }
    }

    protected function renderJson(Report $report): void
    {
        $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Only the code. No headings, no counts — the output is meant to be piped
     * or pasted, and a "3 findings" line in the middle of a PHP array is the
     * kind of helpfulness that makes a tool unusable in a pipeline.
     */
    protected function renderStubs(Report $report): void
    {
        $fixes = $report->fixes();

        if ($fixes->isEmpty()) {
            $this->line('// Nothing to author — no finding names a registry edit.');

            return;
        }

        foreach ($fixes as $fix) {
            $this->line($fix->snippet());
            $this->newLine();
        }
    }

    /**
     * Exit code stays 0 by default — doctor has always been safe to run
     * anywhere, and changing that silently would break schedulers. `--fail-on`
     * is the opt-in CI gate.
     */
    protected function exitCode(Report $report): int
    {
        $floor = $this->option('fail-on');

        if ($floor === null) {
            return self::SUCCESS;
        }

        $severity = Severity::tryFrom((string) $floor);

        if ($severity === null || $severity === Severity::Info) {
            $this->error("--fail-on expects `warning` or `error`, got `{$floor}`.");

            return self::INVALID;
        }

        $reached = $report->problems()->contains(
            fn (Finding $f) => $f->severity->atLeast($severity)
        );

        return $reached ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The names `--only=` accepts. Read from the manager rather than from
     * Doctor::DEFAULT_CHECKS so app-registered checks are listed too — a
     * `--list` that omits half the checks is the "silently skipped category"
     * problem in miniature.
     *
     * @return list<string>
     */
    protected function checkNames(StoryfeedManager $storyfeed): array
    {
        return array_values(array_unique($storyfeed->checkNames()));
    }
}
