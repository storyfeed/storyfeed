<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Diagnostics\Checks\VerbDrift;
use Storyfeed\StoryfeedManager;

/**
 * The static catalog of an app's verb vocabulary — what the app says.
 * (storyfeed:doctor answers the companion question: is the feed healthy.)
 */
class VerbsCommand extends Command
{
    protected $signature = 'storyfeed:verbs {--used : Compare against verbs actually recorded in the feed}';

    protected $description = 'List registered verbs, their AS2.0 types, and grammar/icon coverage';

    public function handle(StoryfeedManager $storyfeed): int
    {
        $verbs = $storyfeed->registeredVerbs();

        ksort($verbs);

        $rows = [];

        foreach ($verbs as $verb => $type) {
            $rows[] = [
                $verb,
                $type instanceof ActivityType ? $type->value : (string) $type,
                $this->coverage($storyfeed->registeredGrammar(), $verb),
                $this->coverage($storyfeed->registeredIcons(), $verb),
                $storyfeed->declaredVerb($verb) ? 'registered' : 'default',
            ];
        }

        $this->table(['Verb', 'AS2.0 type', 'Grammar', 'Icon', 'Source'], $rows);

        return $this->option('used')
            ? $this->reportDrift()
            : self::SUCCESS;
    }

    /**
     * Which registered keys can serve this verb. Grammar and icons are
     * keyed by (object_type, verb) PAIRS, so a verb-only lookup would show
     * "—" for a fully-authored vocabulary and appear to contradict doctor.
     * Instead, list the registered keys whose verb segment matches —
     * "task.create, *.create" — so the catalog shows where coverage comes
     * from. A bare `*.*` catch-all is shown as itself: real, but vacuous
     * (GrammarCoverage deliberately doesn't count it).
     *
     * @param  array<string, mixed>  $registry
     */
    protected function coverage(array $registry, string $verb): string
    {
        $keys = array_filter(
            array_keys($registry),
            function (string $key) use ($verb) {
                $segment = explode('.', $key, 2)[1] ?? null;

                return $segment === $verb || $segment === '*';
            },
        );

        return $keys === [] ? '—' : implode(', ', $keys);
    }

    /**
     * Renders Diagnostics\Checks\VerbDrift rather than re-deriving it.
     *
     * Both this command and doctor want the declared-vs-recorded comparison in
     * both directions, and two implementations of one question is how the two
     * answers come to disagree — which already happened once here, when this
     * command's Grammar column read "—" for a fully-authored vocabulary while
     * doctor simultaneously reported healthy.
     */
    protected function reportDrift(): int
    {
        $findings = app(VerbDrift::class)->run(app(StoryfeedManager::class));

        $undeclared = [];
        $dead = [];

        foreach ($findings as $finding) {
            match ($finding->code) {
                'verbs.undeclared' => $undeclared[] = (string) $finding->subject['verb'],
                'verbs.dead' => $dead[] = (string) $finding->subject['verb'],
                default => $this->warn($finding->message),
            };
        }

        if ($undeclared !== []) {
            $this->newLine();
            $this->warn('Recorded but never declared (likely typos, or verbs needing registration):');

            foreach ($undeclared as $verb) {
                $this->line("  {$verb}");
            }
        }

        if ($dead !== []) {
            $this->newLine();
            $this->line('Declared but never recorded (dead vocabulary):');

            foreach ($dead as $verb) {
                $this->line("  {$verb}");
            }
        }

        if ($undeclared === [] && $dead === []) {
            $this->newLine();
            $this->info('Declared vocabulary matches recorded activity exactly.');
        }

        return self::SUCCESS;
    }
}
