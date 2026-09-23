<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\StoryName;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/**
 * Generate a Story class.
 *
 *   php artisan make:story
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
 * WHAT IT DOES NOT KNOW, IT ASKS, like Laravel's own generators. The verb is
 * a select() over the app's declared vocabulary, and the object a suggest()
 * over its Feedable models; `--verb` and `--model` skip their prompts. The
 * only inference is exact: a class name whose predicate spells exactly one
 * declared verb (see StoryName). Without a terminal, anything still unknown
 * fails with the vocabulary named. Nothing is ever written as a placeholder.
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

    /** The past tense chosen at the prompt, for a name that does not spell it */
    protected ?string $chosenPastTense = null;

    /** The select() answer that writes the headline commented, as without a terminal */
    protected const LEAVE_COMMENTED = 'None of these — leave the headline commented';

    /**
     * Ask for what the name does not settle. Runs only with a terminal, so
     * scripted use goes straight to handle(), which fails instead.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if ($this->option('from-doctor')) {
            return;
        }

        if (! $this->argument('name')) {
            $input->setArgument('name', text(
                label: 'What should the story be named?',
                placeholder: 'E.g. DocumentWasUploaded',
                required: true,
            ));
        }

        if (! $this->option('resource') && ! $this->option('verb') && count($matches = $this->declaredVerbsInName()) !== 1) {
            $input->setOption('verb', $this->askForVerb($matches));
        }

        if (! $this->option('resource') && $this->option('verb')) {
            $this->chosenPastTense = $this->askForPastTense((string) $this->option('verb'));
        }

        if ($this->objectFromName() === null) {
            $input->setOption('model', suggest(
                label: $this->option('resource') ? 'Which model are these stories about?' : 'Which model is the object of this story?',
                options: $this->feedableModels(),
                placeholder: 'E.g. '.$this->rootNamespace().'Models\\Order',
                required: true,
            ));
        }
    }

    /** @param  list<string>  $matches  the declared verbs the name spells: none, or more than one */
    protected function askForVerb(array $matches): string
    {
        $vocabulary = array_keys($this->storyfeed()->registeredVerbs());

        if ($vocabulary === []) {
            return text(
                label: 'Which verb does this story record?',
                placeholder: 'E.g. publish',
                required: true,
                hint: 'The app declares no verbs, so any is allowed. It is stored exactly as typed.',
            );
        }

        return (string) select(
            label: 'Which verb does this story record?',
            options: $vocabulary,
            scroll: 10,
            hint: $matches === []
                ? 'The app\'s declared verbs. Pass --verb for one it does not declare.'
                : 'The name spells '.$this->quoted($matches).'.',
        );
    }

    /**
     * Ask how the verb is written in the past tense, when the name does not
     * spell it and appending is not certain (`ship`: `shipped` or `shiped`).
     * Null when there is nothing to ask, or the developer chose neither.
     */
    protected function askForPastTense(string $verb): ?string
    {
        if (StoryName::parse($this->getNameInput())['predicate'] !== null || $this->certainPastTense($verb) !== null) {
            return null;
        }

        $words = Str::snake($verb, ' ');

        $answer = (string) select(
            label: "How is '{$words}' written in the past tense?",
            options: [...StoryName::pastTenseCandidates($words), self::LEAVE_COMMENTED],
            hint: 'The headline says it, so it must be spelled right.',
        );

        return $answer === self::LEAVE_COMMENTED ? null : $answer;
    }

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

        if ($this->objectFromName() === null) {
            $this->fail(class_basename($this->getNameInput()).' does not name the model the story is about. Pass --model.');
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
        $model = (string) $this->objectFromName();

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
            return str_replace('{{ model }}', Str::studly(class_basename((string) $this->objectFromName())), $stub);
        }

        $verb = $this->resolveVerb($name);
        $object = (string) $this->objectFromName();

        $this->bindings[] = 'Story::for('.$this->objectType($object).")->verb('{$verb}', \\{$name}::class);";

        $past = $this->pastTense($name, $verb);

        if ($past === null) {
            return $this->commentedHeadlines($stub, $verb);
        }

        return str_replace(
            ['{{ headline }}', '{{ groups }}'],
            [':actor '.$past.' :object', $this->groups([$past], $verb)],
            $stub,
        );
    }

    /**
     * The class with its headline commented out beneath the reason, one line
     * per spelling offered, and its group headlines likewise — for a past
     * tense nobody chose. Nothing uncertain lands as live code: the class
     * fails loudly until a line is uncommented, rather than misspell a feed.
     */
    protected function commentedHeadlines(string $stub, string $verb): string
    {
        $words = Str::snake($verb, ' ');
        $candidates = StoryName::pastTenseCandidates($words);
        $reason = "make:story cannot spell '{$words}' in the past tense for certain. Uncomment the right line.";

        $this->components->warn("Wrote the headline commented out: '{$words}' has no certain past tense. Choose one in the class.");

        // By line, so a published stub keeps its own indentation.
        $stub = preg_replace_callback('/^([ \t]*)([^\r\n]*\{\{ headline \}\}[^\r\n]*)/m', fn (array $line) => implode(PHP_EOL, [
            "{$line[1]}// {$reason}",
            ...array_map(fn (string $past) => $line[1].'// '.str_replace('{{ headline }}', ":actor {$past} :object", $line[2]), $candidates),
        ]), $stub) ?? $stub;

        return str_replace('{{ groups }}', $this->groups($candidates, $verb, commented: true), $stub);
    }

    /**
     * `--verb`, else the one declared verb the name spells — which cannot be
     * wrong, because it is only ever one the app declared. Anything else
     * fails, naming the vocabulary; it is never guessed or left blank.
     */
    protected function resolveVerb(string $name): string
    {
        if ($this->option('verb')) {
            return (string) $this->option('verb');
        }

        $matches = $this->declaredVerbsInName();

        if (count($matches) === 1) {
            $this->components->info("Bound to '{$matches[0]}', the declared verb the class name spells.");

            return $matches[0];
        }

        $vocabulary = array_keys($this->storyfeed()->registeredVerbs());
        $base = class_basename($name);

        $this->fail(match (true) {
            count($matches) > 1 => "{$base} spells more than one declared verb: ".$this->quoted($matches)
                .'. Pass --verb to choose.',
            StoryName::parse($name)['predicate'] === null => "{$base} does not follow the {Object}Was{Verbed} "
                .'convention, so the verb cannot be read from it. Pass --verb.',
            $vocabulary === [] => "The app declares no verbs, so the verb cannot be read from {$base}. Pass --verb.",
            default => "No declared verb is spelled by {$base}. Pass --verb; the declared verbs are "
                .$this->quoted($vocabulary).'.',
        });
    }

    /** @return list<string> the declared verbs the class name spells */
    protected function declaredVerbsInName(): array
    {
        return StoryName::verbsIn($this->getNameInput(), array_keys($this->storyfeed()->registeredVerbs()));
    }

    /**
     * `--object` or `--model`, else the name's own object: the words before
     * `Was`, or a resource class name without its `Story` suffix. Null when
     * there is none, which interact() asks about and handle() refuses.
     */
    protected function objectFromName(): ?string
    {
        $given = $this->option('object') ?: $this->option('model');

        if ($given) {
            return (string) $given;
        }

        $name = class_basename($this->getNameInput());

        $object = $this->option('resource')
            ? Str::beforeLast($name, 'Story')
            : StoryName::parse($name)['object'];

        return $object === '' || $object === null ? null : $object;
    }

    /**
     * The models a story can be about: those the morph map names, those
     * registered, and those in app/Models, that are Feedable — suggested, not enforced, since the
     * object may be a morph alias with no class.
     *
     * @return list<string>
     */
    protected function feedableModels(): array
    {
        $feedables = $this->laravel->make(Feedables::class);

        return collect([...array_values(Relation::morphMap()), ...$feedables->registered()])
            ->merge(collect(glob(app_path('Models/*.php')) ?: [])->map(fn (string $file) => $this->qualifyModel(basename($file, '.php'))))
            ->filter(fn (string $class) => class_exists($class) && $feedables->isFeedable($class))
            ->map(fn (string $class) => ltrim($class, '\\'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param  array<int, string>  $verbs */
    protected function quoted(array $verbs): string
    {
        return implode(', ', array_map(fn (string $verb) => "'{$verb}'", $verbs));
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
     * The past tense the DEVELOPER wrote in the class name — correct English by
     * construction — else the one they chose at the prompt, else the verb
     * conjugated where appending is certain. Null otherwise: `ship` could be
     * `shipped` or `shiped`, and a headline must not guess.
     */
    protected function pastTense(string $name, string $verb): ?string
    {
        $predicate = StoryName::parse($name)['predicate'];

        return $predicate !== null
            ? Str::snake($predicate, ' ')
            : $this->chosenPastTense ?? $this->certainPastTense($verb);
    }

    /** `StoryName::certainParticiple()` over the verb as a sentence spells it */
    protected function certainPastTense(string $verb): ?string
    {
        return StoryName::certainParticiple(Str::snake($verb, ' '));
    }

    /**
     * Pre-fill exactly the axes that can apply, each with only pinned tokens.
     *
     * Derived, never reasoned about: this is the same derivation doctor and the
     * coverage assertion use, so a generated stub cannot suggest a token the
     * axis fails to pin — which is the documented lie class, generated. Each
     * headline says `:actor` and `:object` where the axis pins them and the
     * plural forms where it doesn't, with every pinned token listed above it.
     *
     * One headline per spelling given: a single certain one live, or every
     * candidate commented out while the past tense is undecided.
     *
     * A headline in the class is about the class's type, so a grouping that
     * can put other kinds of thing in one row is written as a commented
     * routes/feed.php line instead: uncommented in the class, it would fail
     * at compile.
     *
     * @param  list<string>  $spellings
     */
    protected function groups(array $spellings, string $verb, bool $commented = false): string
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

            $spansTypes = ! $storyfeed->pinsType($axis, 'object') && $storyfeed->axis($axis)?->isRowBacked() !== true;

            $lines[] = $spansTypes
                ? "            // Group::{$constructor} can hold other kinds of thing, so its headline goes in routes/feed.php:"
                : '            // Pinned: '.implode(' ', $tokens);

            foreach ($spellings as $pastTense) {
                // EVERY allowed token, not an arbitrary few: a short slice would
                // look like a considered choice while hiding the rest.
                $headline = (in_array(':actor', $tokens, true) ? ':actor' : ':actors')
                    ." {$pastTense} "
                    .(in_array(':object', $tokens, true) ? ':object' : ':objects');

                $lines[] = $spansTypes
                    ? "            // Story::verb('{$verb}')->grouped(Group::{$constructor}->headline('{$headline}'));"
                    : '            '.($commented ? '// ' : '')."Group::{$constructor}->headline('{$headline}'),";
            }
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
            ['verb', null, InputOption::VALUE_OPTIONAL, 'The stored verb (default: the declared verb the class name spells, else asked)'],
            ['object', null, InputOption::VALUE_OPTIONAL, "The object model or morph alias, or '*' for object-less (default: the class name's, else asked)"],
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
