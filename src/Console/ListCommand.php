<?php

namespace Storyfeed\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\FeedHeadline;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ManifestClosure;

/**
 * Every definition, the way `route:list` shows every route: type, verb, the
 * action it came from (`OrderStory@confirmPayment`, as route:list shows
 * `Controller@method`), what it says with and without an actor, its icon
 * and intent, its group headlines, and the `file:line` it was written on.
 *
 *     php artisan storyfeed:list
 *     php artisan storyfeed:list --type=order --verb=place
 *     php artisan storyfeed:list --json
 *
 * It lists DEFINITIONS (the Story facade, Story classes, the array form),
 * not the hand-written registries, which have no source to show. Reads
 * routes/feed.php even when it is cached, as route:list reads cached routes.
 */
class ListCommand extends Command
{
    protected $signature = 'storyfeed:list
        {--type= : Only definitions for this object type (a morph alias or model class)}
        {--verb= : Only definitions for this verb}
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
            $this->components->info($this->option('type') || $this->option('verb')
                ? 'No definitions match.'
                : 'Nothing is defined yet. Define what activities say in routes/feed.php (php artisan storyfeed:install creates it).');

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Verb', 'Action', 'Headline', 'Anonymous headline', 'Icon', 'Intent', 'Groups', 'Source'],
            array_map(fn (array $row) => [
                $row['type'],
                $row['verb'],
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
                $row['source'],
            ], $rows),
        );

        $this->line('  Showing ['.count($rows).'] definitions');

        return self::SUCCESS;
    }

    /**
     * @return list<array{type: string, verb: string, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, source: string}>
     */
    protected function rows(StoryfeedManager $storyfeed): array
    {
        $rows = [];

        foreach ($storyfeed->storyDefinitions() as $definition) {
            foreach ($definition->objectTypes as $type) {
                $rows[] = $this->row($definition, $type);
            }
        }

        // Wildcards after the types they generalise, as route:list puts
        // catch-alls last.
        usort($rows, fn (array $a, array $b) => [$a['type'] === '*', $a['type'], $a['verb'] === '*', $a['verb']]
            <=> [$b['type'] === '*', $b['type'], $b['verb'] === '*', $b['verb']]);

        return $rows;
    }

    /**
     * @return array{type: string, verb: string, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, source: string}
     */
    protected function row(Verb $definition, string $type): array
    {
        $groups = [];

        foreach ($definition->groupList() as $group) {
            /** @var Group $group */
            $groups[$group->axis] = $group->template();
        }

        return [
            'type' => $type,
            'verb' => $definition->verb,
            'action' => $this->action($definition),
            'headline' => $this->describe($definition->template()),
            'anonymous_headline' => $this->describe($definition->anonymousTemplate()),
            'icon' => $definition->iconToken(),
            'intent' => $definition->glyphIntent(),
            'groups' => $groups,
            'source' => $definition->source,
        ];
    }

    /**
     * What the definition came from: `OrderStory@confirmPayment` for a
     * resource Story class's action, the class for a one-verb Story, and
     * nothing for a line in the file or the array form.
     */
    protected function action(Verb $definition): ?string
    {
        if (($uses = $definition->action()) !== null) {
            return $uses;
        }

        return class_exists($definition->source) ? $definition->source : null;
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
     * @param  list<array{type: string, verb: string, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, source: string}>  $rows
     * @return list<array{type: string, verb: string, action: string|null, headline: string|null, anonymous_headline: string|null, icon: string|null, intent: string|null, groups: array<string, string|null>, source: string}>
     */
    protected function filter(array $rows): array
    {
        $type = $this->option('type');
        $verb = $this->option('verb');

        if (is_string($type) && class_exists($type) && is_a($type, Model::class, true)) {
            $type = (new $type)->getMorphClass();
        }

        return array_values(array_filter($rows, fn (array $row) => (! is_string($type) || $type === '' || $row['type'] === $type)
            && (! is_string($verb) || $verb === '' || $row['verb'] === $verb)));
    }
}
