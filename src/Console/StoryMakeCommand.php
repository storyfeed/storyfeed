<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
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
 *   php artisan make:story TaskWasCompleted --verb=complete --object=Task
 *   php artisan make:story OrderStory --model=Order
 *   php artisan make:story ShipStory --invokable --verb=ship --object=Order
 *   php artisan make:story --from-doctor
 *
 * THREE SHAPES, chosen by option as make:controller chooses its stub, and
 * in the same order: `--model` first, then `--invokable`, then `--resource`,
 * and a message class with none of them.
 *
 *   - a message class (the default), constructed with its data and published;
 *   - `--model=Order` or `--resource`: a resource Story class, one method per
 *     verb, the four conventional ones filled in. `--model` alone implies it,
 *     as `make:controller --model` writes a resource controller, and wins
 *     over `--invokable`, as it does there;
 *   - `--invokable`: one verb's declaration in `__invoke(Verb $verb)`, bound
 *     to a type or, with `--object='*'`, to every type.
 *
 * With no name and no options, it asks what the story will describe first,
 * as make:controller asks which type of controller. Every form PRINTS the line that binds the
 * class, and the line names the verb and the object type, so the class
 * doesn't repeat them:
 *
 *   Story::for(\App\Models\Task::class)->verb('complete', \App\Stories\TaskWasCompleted::class);
 *
 * Like make:controller, it never edits routes/feed.php: the binding is yours
 * to place.
 *
 * WHAT IT DOES NOT KNOW, IT ASKS, like Laravel's own generators. The verb is
 * a select() over the app's declared vocabulary, and the object a suggest()
 * over its Feedable models; `--verb`, `--object` and `--model` skip their
 * prompts. The only inference is exact: a class name whose predicate spells
 * exactly one declared verb (see StoryName), or an invokable class named for
 * a declared verb (`ShipStory` for `ship`). Without a terminal, anything
 * still unknown fails with the vocabulary named. Nothing is ever written as
 * a placeholder.
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

    /** The --from-doctor select() answer that writes no class for the verb */
    protected const SKIP = 'Skip this one';

    /**
     * The shapes, each said as what it describes and the Laravel class it
     * resembles, in columns. Passing a shape's option skips the question.
     */
    protected const SHAPES = [
        'message' => 'One activity, published with its data   like an event',
        'resource' => 'Every activity for one model            like a resource controller',
        'invokable' => 'A single verb                           like a single action controller',
    ];

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

            $this->afterPromptingForMissingArguments($input, $output);
        }

        if ($this->isResource()) {
            if ($this->objectFromName() === null) {
                $input->setOption('model', $this->askForModel());
            }

            return;
        }

        if (! $this->option('verb') && count($matches = $this->declaredVerbsInName()) !== 1) {
            $input->setOption('verb', $this->askForVerb($matches));
        }

        if ($this->option('verb')) {
            $this->chosenPastTense = $this->askForPastTense((string) $this->option('verb'));
        }

        if ($this->objectFromName() === null) {
            $input->setOption('object', suggest(
                label: 'Which model is the object of this story?',
                options: $this->option('invokable') ? ['*', ...$this->feedableModels()] : $this->feedableModels(),
                placeholder: 'E.g. '.$this->rootNamespace().'Models\\Order',
                required: true,
                hint: $this->option('invokable') ? "'*' binds the verb for every type." : '',
            ));
        }
    }

    /**
     * Which shape, asked straight after the name when no option chose one,
     * as make:controller asks which type of controller. The plainest shape
     * is first, as Laravel's `Empty` is: a class for one activity is what
     * the command writes with no option at all.
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        // One hint for the question: per-option text (`info:`) needs a
        // newer Laravel Prompts than the oldest supported lane installs.
        $shape = select(
            label: 'What will this story describe?',
            options: self::SHAPES,
            hint: "The first is published with Storyfeed::publish(new OrderShipped(\$order)), the others by name, with story('order.ship', \$order).",
        );

        if ($shape !== 'message') {
            $input->setOption($shape, true);
        }

        // As make:controller asks a resource controller's model: the name's
        // guess is where a wrong one hides, so the chosen shape asks.
        if ($shape === 'resource') {
            $input->setOption('model', $this->askForModel());
        }
    }

    protected function askForModel(): string
    {
        return suggest(
            label: 'What model is this resource story for?',
            options: $this->feedableModels(),
            placeholder: 'E.g. '.$this->rootNamespace().'Models\\Order',
            required: true,
        );
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

            return null;
        }

        if (! $this->argument('name')) {
            $this->fail('Provide a name, or pass --from-doctor to scaffold from doctor\'s findings.');
        }

        if ($this->option('invokable') && $this->option('resource') && ! $this->option('model')) {
            $this->fail('--invokable and --resource write different classes: an invokable class is one verb, a resource class one method per verb. Pass one.');
        }

        // make:controller's order: --model is read before --invokable.
        if ($this->option('invokable') && $this->option('model')) {
            $this->components->warn('--invokable was ignored: --model writes a resource class.');
            $this->input->setOption('invokable', false);
        }

        if ($this->objectFromName() === null) {
            $this->fail(class_basename($this->getNameInput()).' does not name the model the story is about. Pass '
                .($this->isResource() ? '--model.' : "--object ('*' for every type)."));
        }

        $result = parent::handle();

        if ($this->isResource() && $result !== false) {
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

        $skipped = [];

        foreach ($findings as $finding) {
            /** @var Finding $finding */
            $type = $finding->subject['type'];
            $verb = (string) $finding->subject['verb'];

            // The class name spells the past tense, and the headline reads it
            // back from the name, so an uncertain one (`ship`) is asked, as
            // make:story asks for one class, or else skipped — never guessed
            // into a file called DeliveryWasShiped.
            $past = $this->certainPastTense($verb) ?? $this->askForDoctorPastTense($verb);

            if ($past === null) {
                $skipped[] = [$type, $verb];

                continue;
            }

            $this->input->setArgument('name', $this->doctorClassName($type, $past));
            $this->input->setOption('verb', $verb);
            $this->input->setOption('object', $type ?? '*');

            parent::handle();
        }

        $this->printBindings();

        if ($this->bindings !== []) {
            $this->components->info('Review the generated verbs and headlines — the class names were derived from the '
                .'recorded pairs, so a few will read awkwardly.');
        }

        if ($skipped === []) {
            return;
        }

        // Skipping only happens without a terminal, so each line must run
        // without one too: a complete command per spelling, name included,
        // since the name is where the spelling lives. Pick the right line.
        $this->components->warn('Skipped, because the past tense cannot be spelled for certain. '
            .'Run the line with the right spelling:');

        foreach ($skipped as [$type, $verb]) {
            foreach (StoryName::pastTenseCandidates(Str::snake($verb, ' ')) as $past) {
                $this->line('    php artisan make:story '.$this->doctorClassName($type, $past)
                    .' --verb='.$this->shellArgument($verb).' --object='.$this->shellArgument($type ?? '*'));
            }

            $this->newLine();
        }
    }

    /** `{Object}Was{Verbed}`, the name make:story reads the verb and headline back from */
    protected function doctorClassName(?string $type, string $past): string
    {
        return Str::studly(class_basename($type ?? 'Something')).'Was'.Str::studly($past);
    }

    /** Quoted only where a shell would otherwise eat it: `'*'`, or a class name's backslashes */
    protected function shellArgument(string $value): string
    {
        return preg_match('/^[\w.:-]+$/', $value) === 1 ? $value : "'{$value}'";
    }

    /**
     * For `--from-doctor`: how a verb with no certain past tense is spelled,
     * asked once per verb, or null to skip it — always null without a terminal.
     */
    protected function askForDoctorPastTense(string $verb): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        $words = Str::snake($verb, ' ');

        $answer = (string) select(
            label: "How is '{$words}' written in the past tense?",
            options: [...StoryName::pastTenseCandidates($words), self::SKIP],
            hint: 'The class is named with it, and its headline says it.',
        );

        return $answer === self::SKIP ? null : $answer;
    }

    /**
     * `--model` alone writes a resource class, as `make:controller --model`
     * writes a resource controller.
     */
    protected function isResource(): bool
    {
        return $this->option('resource') || $this->option('model');
    }

    protected function getStub(): string
    {
        $stub = match (true) {
            $this->isResource() => 'story.resource.stub',
            (bool) $this->option('invokable') => 'story.invokable.stub',
            default => 'story.stub',
        };

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

        if ($this->isResource()) {
            return str_replace('{{ model }}', Str::studly(class_basename((string) $this->objectFromName())), $stub);
        }

        $verb = $this->resolveVerb($name);
        $object = (string) $this->objectFromName();

        if ($this->option('invokable')) {
            return $this->invokable($stub, $name, $verb, $object);
        }

        $this->bindings[] = 'Story::for('.$this->objectType($object).")->verb('{$verb}', \\{$name}::class);";

        $stub = $this->message($stub, $object);

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
     * An invokable class: one verb's headlines and icon in `__invoke`, bound
     * to the object's type, or to every type for `'*'`.
     */
    protected function invokable(string $stub, string $name, string $verb, string $object): string
    {
        // Named as a resource names its verbs, `{type}.{verb}`, so it is
        // published with story('order.ship', $order) like one; for every
        // type, by its verb.
        $this->bindings[] = $object === '*'
            ? "Story::verb('{$verb}', \\{$name}::class)->name('{$verb}');"
            : 'Story::for('.$this->objectType($object).")->verb('{$verb}', \\{$name}::class)->name('{$this->morphAlias($object)}.{$verb}');";

        $stub = str_replace('{{ verb }}', $verb, $stub);

        $past = $this->pastTense($name, $verb);

        if ($past === null) {
            return $this->commentedHeadlines($stub, $verb);
        }

        return str_replace(['{{ headline }}', '{{ anonymousHeadline }}'], [":actor {$past} :object", ":object was {$past}"], $stub);
    }

    /**
     * The message half: a constructor taking the object, and the activity
     * about it. The object's model class when one exists, else any model; no
     * object for `'*'`.
     */
    protected function message(string $stub, string $object): string
    {
        $class = $object === '*' ? null : $this->modelClass($object);
        $variable = Str::camel(class_basename($object));

        [$import, $constructor, $activity] = match (true) {
            $object === '*' => ['', '', '$this->activity()'],
            $class !== null => ["use {$class};", 'public '.class_basename($class)." \${$variable}", "\$this->activity(\$this->{$variable})"],
            default => ['use Illuminate\\Database\\Eloquent\\Model;', "public Model \${$variable}", "\$this->activity(\$this->{$variable})"],
        };

        // The import's line goes with it when there is none.
        $stub = preg_replace_callback('/^\{\{ modelImport \}\}\R/m', fn () => $import === '' ? '' : $import.PHP_EOL, $stub) ?? $stub;

        return str_replace(['{{ constructor }}', '{{ activity }}'], [$constructor, $activity], $stub);
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

        // By line, so a published stub keeps its own indentation. The reason
        // goes above the first; an invokable's anonymous headline follows it.
        $reasoned = false;

        $stub = preg_replace_callback('/^([ \t]*)([^\r\n]*\{\{ (?:headline|anonymousHeadline) \}\}[^\r\n]*)/m', function (array $line) use ($candidates, $reason, &$reasoned) {
            $lines = array_map(fn (string $past) => $line[1].'// '.str_replace(
                ['{{ headline }}', '{{ anonymousHeadline }}'],
                [":actor {$past} :object", ":object was {$past}"],
                $line[2],
            ), $candidates);

            if (! $reasoned) {
                $reasoned = true;
                array_unshift($lines, "{$line[1]}// {$reason}");
            }

            return implode(PHP_EOL, $lines);
        }, $stub) ?? $stub;

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

        if (count($matches) === 1 && $this->option('invokable') && StoryName::parse($name)['predicate'] === null) {
            $this->components->info("Bound to '{$matches[0]}', the declared verb the class is named for.");

            return $matches[0];
        }

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

    /**
     * The declared verbs the class name spells. An invokable class not in
     * the `{Object}Was{Verbed}` form may instead be named for its verb, the
     * way a resource class's method is: `ShipStory` or `Ship` is `ship`, and
     * `ConfirmPaymentStory` is `confirm_payment`, if the app declares it.
     *
     * @return list<string>
     */
    protected function declaredVerbsInName(): array
    {
        $declared = array_keys($this->storyfeed()->registeredVerbs());
        $name = $this->getNameInput();

        if ($this->option('invokable') && StoryName::parse($name)['predicate'] === null) {
            $verb = Str::snake(Str::beforeLast(class_basename($name), 'Story') ?: class_basename($name));

            return in_array($verb, $declared, true) ? [$verb] : [];
        }

        return StoryName::verbsIn($name, $declared);
    }

    /**
     * `--model` for a resource class and `--object` for one verb, else the
     * name's own object: a resource class name without its `Story` suffix,
     * or the words before `Was`. Null when there is none, which interact()
     * asks about and handle() refuses.
     */
    protected function objectFromName(): ?string
    {
        $given = $this->isResource() ? $this->option('model') : $this->option('object');

        if ($given) {
            return (string) $given;
        }

        $name = class_basename($this->getNameInput());

        $object = $this->isResource()
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

        $class = $this->modelClass($object);

        return $class !== null ? "\\{$class}::class" : "'".Str::snake(class_basename($object))."'";
    }

    /** The alias the object's type is stored under: its model's morph class, else the snake-cased name. */
    protected function morphAlias(string $object): string
    {
        $class = $this->modelClass($object);

        return $class !== null && is_a($class, Model::class, true)
            ? (new $class)->getMorphClass()
            : Str::snake(class_basename($object));
    }

    /** The model class an object names, if one exists. */
    protected function modelClass(string $object): ?string
    {
        foreach ([$object, $this->rootNamespace().'Models\\'.Str::studly($object), $this->rootNamespace().Str::studly($object)] as $candidate) {
            if (class_exists($candidate)) {
                return ltrim($candidate, '\\');
            }
        }

        return null;
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
            ['resource', 'r', InputOption::VALUE_NONE, 'Generate a resource story class: one method per verb'],
            ['invokable', 'i', InputOption::VALUE_NONE, 'Generate a single verb, invokable story class'],
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'Generate a resource story class for the given model'],
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
