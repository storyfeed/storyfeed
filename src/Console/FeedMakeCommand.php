<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\FeedDefinition;
use Storyfeed\StoryfeedManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate a Feed class.
 *
 *   php artisan make:feed Customer
 *   php artisan make:feed Customer --subject=App\Models\Order --role=context
 *   php artisan make:feed Customer --only=order.placed,order.paid --mode=log
 *   php artisan make:feed Customer --from-doctor
 *
 * `make:feed`, not `make:storyfeed` — the latter reads like it generates the
 * package.
 *
 * WHAT THE GENERATOR IS FOR HERE, specifically: writing the typed constructor.
 * The subject being a constructor argument is what makes an unscoped build
 * impossible — `CustomerFeed::make()` cannot even be called — and writing that
 * argument by hand is the step people skip. Same move as `make:story` writing
 * $verb and $objectType into the file: the guarantee is in the generated code,
 * where a wrong guess is visible in the diff, rather than in runtime inference.
 *
 * The one thing it does NOT do is decide. See fromDoctor().
 */
class FeedMakeCommand extends GeneratorCommand
{
    protected $name = 'make:feed';

    protected $description = 'Create a new Storyfeed feed class';

    protected $type = 'Feed';

    /** @var list<string> */
    protected array $undecided = [];

    public function handle(): ?bool
    {
        // Command::execute() does `(int) handle()`, so null and false both exit
        // 0 and only true exits non-zero. fail() is how a real failure exits.
        // Validated up front, not lazily while filling the stub: a typo'd role
        // on a feed that happens to declare no subject would otherwise pass
        // silently and reappear the day someone adds one.
        $this->role();
        $this->mode();

        if ($this->option('from-doctor')) {
            $this->undecided = $this->undecidedVerbs();
        }

        if (parent::handle() === false) {
            return false;
        }

        $this->newLine();
        $this->components->info(
            'Register it — Storyfeed::feeds(['.class_basename($this->qualifyClass((string) $this->argument('name')))
            .'::class]) in a service provider. Entering it is what scopes a surface; REGISTERING it '
            .'is what lets storyfeed:doctor check its verbs.'
        );

        if ($this->undecided !== []) {
            $this->components->warn(
                count($this->undecided).' undecided verb(s) were written into the file as comments. '
                .'Move each one into only() or except() — until you do, they are still undecided and '
                .'storyfeed:doctor will keep saying so.'
            );
        }

        return null;
    }

    /**
     * The verbs doctor says nobody decided about, transcribed as comments.
     *
     * DELIBERATELY NOT ONE FEED PER VERB, which is what the make:story analogy
     * suggests. An unauthored (type, verb) pair genuinely needs its own story —
     * finding and file correspond. A verb→feed mapping does not: `order.margin_note`
     * does not want a MarginNoteFeed, it wants to be DENIED in the customer feed
     * and allowed in the admin one. And a generated single-verb feed would be a
     * RESTRICTED feed that MENTIONS its verb, which is exactly what FeedCoverage
     * counts as decided — so the generator would turn the check green while
     * nobody decided anything, reintroducing the failure the check was designed
     * to avoid, through a command that looks like a fix.
     *
     * So it writes ONE file, and the file it writes deliberately CANNOT make
     * the check pass: the verbs arrive commented out, and only([]) throws until
     * a human moves them. Everything transcribed was observed; nothing is a
     * guess about who may see what.
     *
     * @return list<string>
     */
    protected function undecidedVerbs(): array
    {
        $findings = $this->storyfeed()->doctor(['feeds'])->withCode('feeds.unclassified');

        if ($findings->isEmpty()) {
            $this->components->info('Doctor reports no undecided verbs — generating an empty allowlist to fill in.');
        }

        return $findings
            ->map(fn (Finding $finding) => (string) $finding->subject['verb'])
            ->values()
            ->all();
    }

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/storyfeed.feed.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/feed.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Feeds';
    }

    /**
     * `Customer` → `CustomerFeed`. Said out loud, because a generator that
     * quietly renames your class is a generator you stop trusting.
     */
    protected function qualifyClass($name): string
    {
        $name = (string) $name;

        if (! str_ends_with(class_basename($name), 'Feed')) {
            $name .= 'Feed';
        }

        return parent::qualifyClass($name);
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        return str_replace(
            ['{{ constructor }}', '{{ scope }}', '{{ verbs }}', '{{ mode }}'],
            [$this->constructor(), $this->scope(), $this->verbs(), $this->mode()],
            $stub,
        );
    }

    /**
     * The typed constructor — the whole point of the class form.
     *
     * Without --subject the feed is global (no constructor, no scope()), which
     * is a real and common shape: an admin feed is not scoped to anything.
     */
    protected function constructor(): string
    {
        $subject = $this->subjectClass();

        if ($subject === null) {
            return '';
        }

        $variable = Str::camel(class_basename($subject));

        return <<<PHP
                /**
                 * The subject, as a CONSTRUCTOR argument: PHP then refuses to build this
                 * feed without one, so no call site can forget the scope. That is the
                 * half of the seam a closure preset cannot carry.
                 */
                public function __construct(protected \\{$subject} \${$variable}) {}


            PHP;
    }

    protected function scope(): string
    {
        $subject = $this->subjectClass();

        if ($subject === null) {
            return '';
        }

        $variable = Str::camel(class_basename($subject));
        $role = $this->role();

        return <<<PHP


                /**
                 * Bind the subject. The role is written here in plain code, so the query
                 * reads the way it would at a call site — and once bound it is LOCKED:
                 * a call site may narrow further, but cannot rebind it.
                 */
                protected function scope(FeedBuilder \$feed): void
                {
                    \$feed->{$role}(\$this->{$variable});
                }
            PHP;
    }

    protected function verbs(): string
    {
        $only = array_filter(explode(',', (string) $this->option('only')));

        $lines = array_map(fn (string $verb) => "            '".trim($verb)."',", $only);

        foreach ($this->undecided as $verb) {
            $lines[] = "            // '{$verb}',";
        }

        if ($this->undecided !== []) {
            array_splice($lines, count($only), 0, [
                '            // Undecided verbs, from storyfeed:doctor. Move each one into',
                '            // only() above or into an except([...]) call — leaving them',
                '            // commented decides nothing, and doctor will keep saying so.',
            ]);
        }

        return $lines === []
            ? "            // 'order.placed', 'order.delivered' — the verbs this audience may see."
            : implode(PHP_EOL, $lines);
    }

    protected function mode(): string
    {
        $mode = (string) $this->option('mode');

        if ($mode === '') {
            return '';
        }

        if (! in_array($mode, ['log', 'live', 'summary'], true)) {
            $this->fail("Unknown feed mode [{$mode}]. Valid modes: log, live, summary.");
        }

        return "->{$mode}()";
    }

    protected function role(): string
    {
        $role = (string) ($this->option('role') ?: 'context');

        if (! in_array($role, FeedDefinition::ROLES, true)) {
            $this->fail("Unknown role [{$role}]. Valid roles: ".implode(', ', FeedDefinition::ROLES).'.');
        }

        return $role;
    }

    protected function subjectClass(): ?string
    {
        $subject = (string) $this->option('subject');

        if ($subject === '') {
            return null;
        }

        foreach ([$subject, $this->rootNamespace().'Models\\'.Str::studly($subject), $this->rootNamespace().Str::studly($subject)] as $candidate) {
            if (class_exists($candidate)) {
                return ltrim($candidate, '\\');
            }
        }

        // Written anyway: an unresolvable class in the generated file is a
        // visible, editable mistake, which beats silently dropping the
        // constructor and with it the guarantee.
        $this->components->warn(
            "[{$subject}] could not be resolved to a class. Writing it into the constructor as given — fix it there."
        );

        return ltrim($subject, '\\');
    }

    protected function storyfeed(): StoryfeedManager
    {
        return $this->laravel->make(StoryfeedManager::class);
    }

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['subject', null, InputOption::VALUE_OPTIONAL, 'The model this feed is scoped to (omit for a global feed)'],
            ['role', null, InputOption::VALUE_OPTIONAL, 'The role the subject binds to: '.implode(', ', FeedDefinition::ROLES).' (default: context)'],
            ['only', null, InputOption::VALUE_OPTIONAL, 'Comma-separated verbs for the allowlist'],
            ['mode', null, InputOption::VALUE_OPTIONAL, 'Read mode: log, live or summary'],
            ['from-doctor', null, InputOption::VALUE_NONE, "Transcribe doctor's undecided verbs into the file, commented"],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing feed'],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The feed class name, e.g. Customer or CustomerFeed'],
        ];
    }
}
