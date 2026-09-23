<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\StoryName;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate a Story class.
 *
 *   php artisan make:story DocumentWasUploaded
 *   php artisan make:story TaskWasCompleted --verb=complete --model=Task
 *   php artisan make:story OrderStory --resource --model=Order
 *   php artisan make:story --from-doctor
 *
 * Every form PRINTS the line that binds the class, and the line names the
 * verb and the object type, so a one-verb class doesn't repeat them:
 *
 *   Story::for(\App\Models\Task::class)->verb('complete', \App\Stories\TaskWasCompleted::class);
 *
 * `--resource` writes a resource Story class, one method per verb, with the
 * four conventional ones filled in. Like make:controller, it never edits
 * routes/feed.php: the binding is yours to place.
 *
 * THIS IS WHERE INFERENCE LIVES, and nowhere else. The `Was` infix is parsed
 * into an object and a verb, and both are PRINTED in the binding line. A wrong
 * guess is therefore visible and editable, rather than a runtime behaviour that
 * self-registers a wrong verb past strict mode. The verb is named only when the
 * app's declared vocabulary settles it; otherwise the line says TODO.
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

    /** @var list<string> the binding line of each class written this run */
    protected array $bindings = [];

    public function handle(): ?bool
    {
        // NOTE on return values: Command::execute() does `(int) handle()`, so
        // `null` and `false` BOTH exit 0 and only `true` exits non-zero. Use
        // fail() for real failures rather than relying on that inversion.
        if ($this->option('from-doctor')) {
            $this->fromDoctor();
            $this->printBindings();

            return null;
        }

        if (! $this->argument('name')) {
            $this->fail('Provide a name, or pass --from-doctor to scaffold from doctor\'s findings.');
        }

        $result = parent::handle();

        if ($this->option('resource') && $result !== false) {
            $this->bindings[] = 'Story::resource('.$this->modelReference().', \\'.$this->qualifyClass($this->getNameInput()).'::class);';
        }

        $this->printBindings();

        return $result;
    }

    /**
     * Print the line that binds each class written, for routes/feed.php.
     * Like make:controller, the file itself is never edited.
     */
    protected function printBindings(): void
    {
        if ($this->bindings === []) {
            return;
        }

        $this->components->info('Bind it in '.app(DefinitionsFile::class)->relativePath().':');

        foreach ($this->bindings as $binding) {
            $this->line('    '.$binding);
        }

        $this->newLine();
    }

    /**
     * The model the resource is about: `--model`, else the class name without
     * its `Story` suffix. Written into the printed line, where a wrong guess
     * is plain to see.
     */
    protected function modelReference(): string
    {
        $model = (string) ($this->option('model') ?: Str::beforeLast(class_basename($this->getNameInput()), 'Story'));

        if ($model === '') {
            return 'TODO::class';
        }

        foreach ([$model, $this->rootNamespace().'Models\\'.Str::studly($model), $this->rootNamespace().Str::studly($model)] as $candidate) {
            if (class_exists($candidate)) {
                return '\\'.ltrim($candidate, '\\').'::class';
            }
        }

        return '\\'.$this->rootNamespace().'Models\\'.Str::studly(class_basename($model)).'::class';
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
        $stub = $this->option('resource') ? 'story.resource.stub' : 'story.stub';

        // Laravel's convention: an app can drop its own stub in base_path.
        $published = $this->laravel->basePath("stubs/storyfeed.{$stub}");

        return file_exists($published) ? $published : __DIR__.'/../../stubs/'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Stories';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        if ($this->option('resource')) {
            return str_replace('{{ model }}', Str::studly(class_basename((string) ($this->option('model') ?: Str::beforeLast(class_basename($name), 'Story')))), $stub);
        }

        $parsed = StoryName::parse($name, array_keys($this->storyfeed()->registeredVerbs()));

        $verb = (string) ($this->option('verb') ?: $parsed['verb'] ?: 'TODO');
        $object = (string) ($this->option('object') ?: $this->option('model') ?: $parsed['object'] ?: 'TODO');

        $this->reportInference($name, $parsed, $verb);

        $this->bindings[] = 'Story::for('.$this->objectType($object).")->verb('{$verb}', \\{$name}::class);";

        return str_replace(
            ['{{ headline }}', '{{ groups }}'],
            [$this->headline($name, $verb), $this->groups($verb)],
            $stub,
        );
    }

    /**
     * Say what was guessed, and how confidently.
     *
     * The point of generator-time inference is that a wrong guess is VISIBLE.
     * Printing it silently would give away most of that.
     *
     * @param  array{object: string|null, verb: string|null}  $parsed
     */
    protected function reportInference(string $name, array $parsed, string $verb): void
    {
        if ($this->option('verb')) {
            return;
        }

        if ($parsed['object'] === null) {
            $this->components->warn(
                class_basename($name).' does not follow the {Object}Was{Verbed} convention, so the verb could '
                .'not be read from it. Name it in the binding line, or pass --verb.'
            );

            return;
        }

        if ($parsed['verb'] === null) {
            $this->components->warn(
                'No declared verb matches '.class_basename($name).", so the binding line says 'TODO'. Name the verb "
                .'there, or pass --verb. It is stored verbatim, so it is not guessed from the spelling.'
            );

            return;
        }

        $this->components->info("Bound to '{$verb}' — the declared verb the class name matches.");
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

        if ($object === 'TODO') {
            return 'TODO::class';
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
            : $storyfeed->axesApplicableTo(ActivityRoles::GROUPABLE);

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
            ['verb', null, InputOption::VALUE_OPTIONAL, 'The stored verb (default: the declared verb the class name matches)'],
            ['object', null, InputOption::VALUE_OPTIONAL, "The object model or morph alias, or '*' for object-less"],
            ['resource', 'r', InputOption::VALUE_NONE, 'Write a resource Story class: one method per verb'],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model the story is about (the resource binding, or the object)'],
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
