<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\StoryName;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate a Story class.
 *
 *   php artisan make:story DocumentWasUploaded
 *   php artisan make:story DocumentWasUploaded --object=App\Models\Document
 *   php artisan make:story --from-doctor
 *
 * THIS IS WHERE INFERENCE LIVES, and nowhere else. The `Was` infix is parsed
 * into an object and a verb, and both are WRITTEN INTO THE FILE as literals. A
 * wrong guess is therefore visible in the diff and editable, rather than a
 * runtime behaviour that self-registers a wrong verb past strict mode. It also
 * means the heuristic can be aggressive: a human reviews every result.
 *
 * `--from-doctor` scaffolds from doctor's findings. That is NOT the parked
 * `storyfeed:eject`, and the distinction matters: eject was rejected because it
 * emitted code derived from an INFERENCE ENGINE (verb from event name, target
 * from belongsTo, label from the first string-ish column). Nothing here infers
 * what happened. Every pair it scaffolds was actually recorded, every axis it
 * lists actually applies per the compiled recipes, and every token it offers is
 * actually pinned. Transcribing what the system observed is doctor's whole job;
 * guessing what it meant is what stays banned.
 */
class StoryMakeCommand extends GeneratorCommand
{
    protected $name = 'make:story';

    protected $description = 'Create a new Storyfeed story class';

    protected $type = 'Story';

    public function handle(): ?bool
    {
        // NOTE on return values: Command::execute() does `(int) handle()`, so
        // `null` and `false` BOTH exit 0 and only `true` exits non-zero. Use
        // fail() for real failures rather than relying on that inversion.
        if ($this->option('from-doctor')) {
            $this->fromDoctor();

            return null;
        }

        if (! $this->argument('name')) {
            $this->fail('Provide a name, or pass --from-doctor to scaffold from doctor\'s findings.');
        }

        return parent::handle();
    }

    /**
     * One Story per unauthored (type, verb) pair doctor actually found.
     */
    protected function fromDoctor(): void
    {
        $findings = $this->storyfeed()->doctor(['grammar'])->withCode('grammar.missing');

        if ($findings->isEmpty()) {
            $this->components->info('Nothing to scaffold — every recorded activity already has a headline.');

            return;
        }

        foreach ($findings as $finding) {
            /** @var Finding $finding */
            $type = $finding->subject['type'];
            $verb = (string) $finding->subject['verb'];

            $name = Str::studly((string) ($type ?? 'Something')).'Was'.Str::studly(StoryName::participle($verb));

            $this->input->setArgument('name', $name);
            $this->input->setOption('verb', $verb);
            $this->input->setOption('object', $type ?? '*');

            parent::handle();
        }

        $this->newLine();
        $this->components->info('Review the generated verbs and headlines — the class names were derived from the '
            .'recorded pairs, so a few will read awkwardly.');
    }

    protected function getStub(): string
    {
        // Laravel's convention: an app can drop its own stub in base_path.
        $published = $this->laravel->basePath('stubs/storyfeed.story.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/story.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Stories';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $parsed = StoryName::parse($name, array_keys($this->storyfeed()->registeredVerbs()));

        $verb = (string) ($this->option('verb') ?: $parsed['verb'] ?: 'TODO');
        $object = (string) ($this->option('object') ?: $parsed['object'] ?: 'TODO');

        $this->reportInference($name, $parsed, $verb);

        return str_replace(
            ['{{ objectType }}', '{{ verb }}', '{{ headline }}', '{{ groups }}'],
            [$this->objectType($object), $verb, $this->headline($name, $verb), $this->groups($verb)],
            $stub,
        );
    }

    /**
     * Say what was guessed, and how confidently.
     *
     * The point of generator-time inference is that a wrong guess is VISIBLE.
     * Writing it into the file silently would give away most of that.
     *
     * @param  array{object: string|null, verb: string|null, confident: bool}  $parsed
     */
    protected function reportInference(string $name, array $parsed, string $verb): void
    {
        if ($this->option('verb')) {
            return;
        }

        if ($parsed['object'] === null) {
            $this->components->warn(
                class_basename($name).' does not follow the {Object}Was{Verbed} convention, so the object and '
                .'verb could not be read from it. Fill in $objectType and $verb, or pass --object and --verb.'
            );

            return;
        }

        $this->components->info("Wrote \$verb = '{$verb}' — inferred from the class name.");

        if (! $parsed['confident']) {
            $this->components->warn(
                "'{$verb}' is a guess: it is not in the app's declared vocabulary. Check it — the verb is stored "
                .'verbatim, and a Story registers its own verb, so nothing downstream will second-guess it.'
            );
        }
    }

    /**
     * A model class when one resolves, else the morph alias as a string.
     *
     * The class form is preferred in the output because a rename is then an
     * IDE-checked change, unlike the string 'document'.
     */
    protected function objectType(string $object): string
    {
        if ($object === '*') {
            return "'*'";
        }

        foreach ([$object, $this->rootNamespace().'Models\\'.Str::studly($object), $this->rootNamespace().Str::studly($object)] as $candidate) {
            if (class_exists($candidate)) {
                return '\\'.ltrim($candidate, '\\').'::class';
            }
        }

        return "'".Str::snake(class_basename($object))."'";
    }

    /**
     * A skeleton headline using the participle the DEVELOPER wrote in the class
     * name — correct English by construction, unlike conjugating the imperative
     * ('create' + 'ed' = 'createed').
     *
     * Still marked TODO. A generated sentence that reads plausibly is one nobody
     * rewrites, and only taste validates prose — so the stub is deliberately
     * obvious rather than nearly right.
     */
    protected function headline(string $name, string $verb): string
    {
        $participle = Str::of(class_basename($name))->after('Was')->snake(' ')->toString();

        return ':actor '.($participle !== '' ? $participle : 'TODO '.$verb).' :object';
    }

    /**
     * Pre-fill exactly the axes that can apply, each with only pinned tokens.
     *
     * Derived, never reasoned about: this is the same derivation doctor and the
     * coverage assertion use, so a generated stub cannot suggest a token the
     * axis fails to pin — which is the documented lie class, generated.
     */
    protected function groups(string $verb): string
    {
        $storyfeed = $this->storyfeed();

        /** @var list<string> $requested */
        $requested = array_filter(explode(',', (string) $this->option('axes')));

        $axes = $requested !== []
            ? $requested
            : $storyfeed->axesApplicableTo(['actor', 'object', 'target', 'context']);

        $lines = [];

        foreach ($axes as $axis) {
            $tokens = $storyfeed->aggregateTokens($axis);

            if ($tokens === null) {
                continue;
            }

            $constructor = match ($axis) {
                'actors' => 'byActors()',
                'targets' => 'byTargets()',
                'object' => 'byObject()',
                'repeat' => 'repeat()',
                'composite' => 'composite()',
                '*' => 'any()',
                default => "on('{$axis}')",
            };

            // EVERY allowed token, not an arbitrary few: the developer deletes
            // what they do not want, and a short slice would look like a
            // considered choice while hiding the rest of the vocabulary.
            $lines[] = "            Group::{$constructor}->headline('TODO ".implode(' ', $tokens)."'),";
        }

        return $lines === []
            ? '            // No axis applies to this activity — it will render as a plain node.'
            : implode(PHP_EOL, $lines);
    }

    protected function storyfeed(): StoryfeedManager
    {
        return $this->laravel->make(StoryfeedManager::class);
    }

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['verb', null, InputOption::VALUE_OPTIONAL, 'The stored verb (default: read from the class name)'],
            ['object', null, InputOption::VALUE_OPTIONAL, "The object model or morph alias, or '*' for object-less"],
            ['axes', null, InputOption::VALUE_OPTIONAL, 'Comma-separated axes to pre-fill (default: all that apply)'],
            ['from-doctor', null, InputOption::VALUE_NONE, 'Scaffold one story per unauthored pair doctor found'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing story'],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::OPTIONAL, 'The story class name, e.g. DocumentWasUploaded'],
        ];
    }
}
