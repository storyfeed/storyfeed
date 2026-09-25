<?php

namespace Storyfeed\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Checks\Retention;
use Storyfeed\FeedHeadline;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Registrar;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ManifestClosure;

/**
 * Every definition, the way `route:list` shows every route: type, verb, its
 * name (what `story()` references it by), the action it came from (`OrderStory@confirmPayment`, as route:list shows
 * `Controller@method`), what it says with and without an actor, its icon
 * and intent, its group headlines, the period they group per, what it
 * keeps the latest of, the `file:line` it was written on, and its story
 * middleware.
 *
 *     php artisan storyfeed:list
 *     php artisan storyfeed:list --type=order --verb=place
 *     php artisan storyfeed:list --name=billing.
 *     php artisan storyfeed:list -v          # with the middleware column
 *     php artisan storyfeed:list --json
 *
 * Middleware is shown resolved, as route:list shows it: the `default` group
 * expanded, aliases as their classes with any arguments, exclusions gone. As
 * route:list does, the table shows it with `-v`; `--json` always has it.
 *
 * It lists DEFINITIONS (lines, resource classes, message classes),
 * not the hand-written registries, which have no source to show. Reads
 * routes/feed.php even when it is cached, as route:list reads cached routes.
 */
class ListCommand extends Command
{
    protected $signature = 'storyfeed:list
        {--type= : Only definitions for this object type (a morph alias or model class)}
        {--verb= : Only definitions for this verb}
        {--name= : Only definitions whose name contains this}
        {--json : Emit the definitions as JSON}';

    protected $description = 'List every story definition, with its source file and line';

    public function handle(StoryfeedManager $storyfeed): int
    {
        $rows = $this->filter($this->rows($storyfeed));

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info($this->option('type') || $this->option('verb') || $this->option('name')
                ? 'No definitions match.'
                : 'Nothing is defined yet. Define what activities say in routes/feed.php (php artisan storyfeed:install creates it).');

            return self::SUCCESS;
        }

        $middleware = $this->output->isVerbose();

        $this->table(
            ['Type', 'Verb', 'Name', 'Action', 'Headline', 'Anonymous headline', 'Icon', 'Intent', 'Groups', 'Period', 'Keep latest', 'Source', ...($middleware ? ['Middleware'] : [])],
            array_map(fn (array $row) => [
                $row['type'],
                $row['verb'],
                $row['name'] ?? '',
                $row['action'] ?? '',
                $row['headline'] ?? '',
                $row['anonymous_headline'] ?? '',
                $row['icon'] ?? '',
                $row['intent'] ?? '',
                implode("\n", array_map(
                    fn (string $axis, ?string $headline) => $headline === null ? $axis : "{$axis}: {$headline}",
                    array_keys($row['groups']),
                    $row['groups'],
                )),
                $row['period'] ?? '',
                $row['keep_latest'] ?? '',
                $row['source'],
                ...($middleware ? [implode("\n", $row['middleware'])] : []),
            ], $rows),
        );

        $this->line('  Showing ['.count($rows).'] definitions');

        return self::SUCCESS;
    }

    /**
     * @return list<array{type: string, verb: string, name: string|null, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, keep_latest: string|null, period: string|null, source: string, middleware: list<string>}>
     */
    protected function rows(StoryfeedManager $storyfeed): array
    {
        $rows = [];

        foreach ($storyfeed->storyDefinitions() as $definition) {
            foreach ($definition->objectTypes as $type) {
                $rows[] = $this->row($definition, $type, $storyfeed);
            }
        }

        // Wildcards after the types they generalise, as route:list puts
        // catch-alls last.
        usort($rows, fn (array $a, array $b) => [$a['type'] === '*', $a['type'], $a['verb'] === '*', $a['verb']]
            <=> [$b['type'] === '*', $b['type'], $b['verb'] === '*', $b['verb']]);

        return $rows;
    }

    /**
     * @return array{type: string, verb: string, name: string|null, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, keep_latest: string|null, period: string|null, source: string, middleware: list<string>}
     */
    protected function row(Verb $definition, string $type, StoryfeedManager $storyfeed): array
    {
        $groups = [];

        foreach ($definition->groupList() as $group) {
            /** @var Group $group */
            $groups[$group->axis] = $group->template();
        }

        return [
            'type' => $type,
            'verb' => $definition->verb,
            'name' => $definition->names()["{$type}.{$definition->verb}"] ?? null,
            'action' => $this->action($definition),
            'headline' => $this->describe($definition->template()),
            'anonymous_headline' => $this->describe($definition->anonymousTemplate()),
            'icon' => $definition->iconToken(),
            'intent' => $definition->glyphIntent(),
            'groups' => $groups,
            'period' => $this->period($definition, $type, $storyfeed),
            'keep_latest' => $this->keepLatest($definition),
            'source' => $definition->source,
            'middleware' => $this->middleware($definition, $type, $storyfeed),
        ];
    }

    /**
     * What a publish of this definition runs: its own declaration when it
     * made one, and otherwise what the type → verb ladder gives its key,
     * which may be a broader definition's.
     *
     * @return list<string>
     */
    protected function middleware(Verb $definition, string $type, StoryfeedManager $storyfeed): array
    {
        $declared = $definition->declaredMiddleware();

        $middleware = $declared !== null || $definition->verb === '*'
            ? app(Registrar::class)->gatherMiddleware($declared['middleware'] ?? [], $declared['excluded'] ?? [])
            : $storyfeed->middleware($type === '*' ? null : $type, $definition->verb);

        return array_map(
            fn (string|Closure $middleware) => $middleware instanceof Closure ? 'Closure ('.ManifestClosure::location($middleware).')' : $middleware,
            $middleware,
        );
    }

    /**
     * What the definition came from: `OrderStory@confirmPayment` for a
     * resource Story class's action, the class for a message class or an
     * invokable one (stored `ShipStory@__invoke`, shown as `route:list`
     * shows an invokable controller), and nothing for a line in the file.
     */
    protected function action(Verb $definition): ?string
    {
        $action = $definition->action();

        return $action === null ? null : Str::before($action, '@__invoke');
    }

    /**
     * The calendar period the definition's groups live in (`hour`, `day`,
     * `week`, `month`): its own declaration when it made one, and otherwise
     * what the type → verb ladder gives its key, as middleware is shown. A
     * fallback that declares none shows none.
     */
    protected function period(Verb $definition, string $type, StoryfeedManager $storyfeed): ?string
    {
        if (($period = $definition->period()) !== null) {
            return $period->value;
        }

        return $definition->verb === '*' ? null : $storyfeed->period($type === '*' ? null : $type, $definition->verb)->value;
    }

    /**
     * `per object`, `per object, actor`, `per object within 10 minutes`:
     * what `->keepLatest()` keeps, or nothing when every row is kept.
     */
    protected function keepLatest(Verb $definition): ?string
    {
        if (($latest = $definition->latestKept()) === null) {
            return null;
        }

        return 'per '.implode(', ', $latest['per'])
            .($latest['within'] === null ? '' : ' within '.Retention::describe($latest['within']));
    }

    protected function describe(string|Closure|FeedHeadline|null $headline): ?string
    {
        return match (true) {
            $headline instanceof FeedHeadline => "trans({$headline->key})",
            $headline instanceof Closure => 'Closure ('.ManifestClosure::location($headline).')',
            default => $headline,
        };
    }

    /**
     * @param  list<array{type: string, verb: string, name: string|null, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, keep_latest: string|null, period: string|null, source: string, middleware: list<string>}>  $rows
     * @return list<array{type: string, verb: string, name: string|null, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, keep_latest: string|null, period: string|null, source: string, middleware: list<string>}>
     */
    protected function filter(array $rows): array
    {
        $type = $this->option('type');
        $verb = $this->option('verb');
        $name = $this->option('name');

        if (is_string($type) && class_exists($type) && is_a($type, Model::class, true)) {
            $type = (new $type)->getMorphClass();
        }

        return array_values(array_filter($rows, fn (array $row) => (! is_string($type) || $type === '' || $row['type'] === $type)
            && (! is_string($verb) || $verb === '' || $row['verb'] === $verb)
            // As route:list's --name: a part of the name.
            && (! is_string($name) || $name === '' || ($row['name'] !== null && str_contains($row['name'], $name)))));
    }
}
