<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Throwable;

/**
 * The `Storyfeed` section of `php artisan about`.
 *
 * WHY STATE, NOT STALENESS DETECTION. A consumer edited `toFeed()`, deployed,
 * saw nothing change, and two sessions spent an hour on stylesheets. Another
 * never found a shipped guard and put broken prose on a dashboard. Laravel's
 * answer to the same trap — an edited config file behind `config:cache` — is
 * not to detect that the cache is stale, it is to put CACHED in front of you
 * where you already look. So this says what is loaded, what is cached and
 * since when, what is scheduled, and points at the doctor.
 *
 * CHEAP BY CONSTRUCTION. The provider registers a closure, so none of this
 * runs outside `about`. Inside it, nothing here scans a table: the doctor row
 * runs only the checks that read config, schema presence and the in-memory
 * compile (`tables`, `recording`, `manifest`), and says so. The full run,
 * with its row counts, stays `storyfeed:doctor`.
 *
 * TRUE ON A FRESH INSTALL. No definitions file, no manifest, no tables, no
 * database connection: every row still says something true, and nothing
 * throws — a check that throws becomes a finding, as it does in the doctor.
 */
class About
{
    /** The checks cheap enough to run inside `about`. None of them scans rows. */
    public const CHECKS = ['tables', 'recording', 'manifest'];

    /** The package commands worth scheduling, as `about` rows. */
    public const SCHEDULED = ['curate', 'trickle', 'close-batches'];

    public function __construct(
        protected Application $app,
        protected StoryfeedManager $storyfeed,
        protected DefinitionsFile $file,
        protected StoryManifest $manifest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $cached = $this->manifest->exists();

        // Before the doctor row: the manifest check compiles in memory, and
        // that loads the file, so asked afterwards it would always say loaded.
        $rows = ['Definitions' => $this->definitions($cached)];

        $rows['Cache'] = AboutCommand::format(
            $cached ? date('Y-m-d H:i:s', (int) filemtime($this->manifest->path())) : null,
            console: fn (?string $at) => $at === null
                ? '<fg=yellow;options=bold>NOT CACHED</>'
                : "<fg=green;options=bold>CACHED</> {$at}",
            json: fn (?string $at) => $at === null ? false : $at,
        );

        $verbs = array_keys($this->storyfeed->registeredVerbs());
        $declared = count(array_filter($verbs, $this->storyfeed->declaredVerb(...)));

        $rows['Verbs'] = AboutCommand::format(
            $declared,
            console: fn (int $count) => $count.' declared ('.(count($verbs) - $count).' shipped defaults)',
        );
        $rows['Object types'] = count($this->storyfeed->registeredObjectTypes());
        $rows['Stories'] = count($this->storyfeed->registeredStories());
        $rows['Feeds'] = count($this->storyfeed->feedNames());

        $rows['Recording'] = AboutCommand::format(
            $this->storyfeed->isRecording(),
            console: fn (bool $on) => $on ? 'ENABLED' : '<fg=red;options=bold>OFF</>',
        );

        foreach ($this->scheduled() as $command => $event) {
            $rows[ucfirst($command).' schedule'] = AboutCommand::format(
                $event?->expression,
                console: fn (?string $expression) => $expression === null
                    ? 'not scheduled'
                    : $expression.($event?->withoutOverlapping ? ', without overlapping' : ''),
            );
        }

        $rows['Doctor'] = $this->doctor();

        return $rows;
    }

    protected function definitions(bool $cached): string
    {
        return match (true) {
            $this->file->path() === null => 'off',
            ! $this->file->exists() => 'none ('.$this->file->relativePath().' not found)',
            $cached && ! $this->file->isLoaded() => $this->file->relativePath().' (cached — not loaded at boot)',
            default => $this->file->relativePath(),
        };
    }

    /**
     * The first scheduled event for each package command, or null.
     *
     * @return array<string, Event|null>
     */
    protected function scheduled(): array
    {
        $events = $this->app->make(Schedule::class)->events();

        $scheduled = [];

        foreach (self::SCHEDULED as $command) {
            $scheduled[$command] = null;

            foreach ($events as $event) {
                if (preg_match('/(^|\s|\')storyfeed:'.preg_quote($command, '/').'(\'|\s|$)/', (string) $event->command)) {
                    $scheduled[$command] = $event;

                    break;
                }
            }
        }

        return $scheduled;
    }

    /**
     * The quick checks' verdict, and where the full one lives.
     */
    protected function doctor(): Closure
    {
        try {
            $findings = $this->storyfeed->doctor(self::CHECKS)->problems()->all();
            $total = count($this->storyfeed->checkNames());
        } catch (Throwable $e) {
            // Doctor already turns a throwing check into a finding; this is
            // only for a check registry that cannot even be resolved.
            return AboutCommand::format($e->getMessage(), console: fn () => '<fg=red;options=bold>could not run</> — storyfeed:doctor');
        }

        $codes = array_values(array_unique(array_map(fn (Finding $finding) => $finding->code, $findings)));
        $scope = count(self::CHECKS)." of {$total} checks";

        return AboutCommand::format(
            $codes,
            console: fn (array $codes) => ($codes === []
                ? '<fg=green;options=bold>OK</>'
                : '<fg=yellow;options=bold>'.implode(', ', $codes).'</>')
                ." ({$scope}) — storyfeed:doctor runs all",
        );
    }
}
