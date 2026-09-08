<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;

/**
 * The publish-site inventory: what this app tells stories about, and what it
 * could but doesn't.
 *
 * Three commands, three distinct questions, and the division is worth stating
 * because two of them already disagreed once:
 *
 *   storyfeed:verbs   — the VOCABULARY (what the app can say)
 *   storyfeed:doctor  — the HEALTH (what is broken now)
 *   storyfeed:stories — the INVENTORY (what publishes, and what doesn't)
 *
 * This is the answer to "a developer joining in six months cannot discover what
 * the feed publishes or from where." Call sites are free to live wherever they
 * belong — observers, listeners, actions, jobs — because discoverability is a
 * TOOL rather than a filing convention. That also means it works for publishes
 * this package never wired.
 */
class StoriesCommand extends Command
{
    protected $signature = 'storyfeed:stories
        {--gaps : Only rows that need attention}
        {--json : Emit the inventory as JSON}
        {--since=30 : Days after which a story counts as quiet}';

    protected $description = 'Inventory what publishes to the feed, and what could but does not';

    public function handle(StoryfeedManager $storyfeed): int
    {
        $rows = $this->inventory($storyfeed);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $shown = $this->option('gaps')
            ? array_filter($rows, fn (array $row) => $row['status'] !== 'ok')
            : $rows;

        if ($shown === []) {
            $this->info($rows === [] ? 'Nothing publishes to the feed yet.' : 'Every story is ok.');

            return self::SUCCESS;
        }

        $this->table(
            ['Story / source', 'Verb', 'Object', 'Grammar', 'Icon', 'Aggregates', 'Last recorded', 'Status'],
            array_map(fn (array $row) => [
                // Basename in the table, FQCN in --json: a 40-character
                // namespace wraps the row and buries the columns that matter.
                class_exists($row['source']) ? class_basename($row['source']) : $row['source'],
                $row['verb'],
                $row['object'] ?? '—',
                $row['grammar'] ? '✓' : '✗',
                $row['icon'] ? '✓' : '✗',
                $row['aggregates'],
                $row['last_recorded'] ?? 'never',
                $row['status'],
            ], $shown),
        );

        $this->summary($rows);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function inventory(StoryfeedManager $storyfeed): array
    {
        $rows = [];

        $lastRecorded = $this->lastRecordedByPair();
        $roleMap = $this->roleMap();

        // 1. Registered stories, named by their class or ad-hoc key.
        foreach ($storyfeed->storyDefinitions() as $definition) {
            foreach ($definition->pairs() as [$type, $verb]) {
                $rows[] = $this->row($storyfeed, $definition->source, $type, $verb, $lastRecorded, $roleMap);
            }
        }

        // 2. Pairs recorded WITHOUT a story — the call sites the package never
        // saw. Including them is the point: the inventory must describe the app,
        // not only the part that adopted this layer.
        $known = array_map(fn (array $row) => ($row['object'] ?? '*').'.'.$row['verb'], $rows);

        foreach (array_keys($lastRecorded) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            [$type, $verb] = explode('.', (string) $key, 2);

            $rows[] = $this->row(
                $storyfeed,
                '(call site)',
                $type === '*' ? null : $type,
                $verb,
                $lastRecorded,
                $roleMap,
            );
        }

        // 3. Declared surface that publishes nothing at all — DELEGATED to the
        // doctor check rather than re-derived here. "Unwired" is one question,
        // and two implementations of one question is how two commands come to
        // disagree: the first draft of this method compared only `object_type`
        // and duly reported the User and Customer models as unwired while they
        // were sitting in the feed as the actor and target of every activity.
        foreach ($storyfeed->doctor(['surface'])->withCode('surface.unwired') as $finding) {
            $rows[] = [
                'source' => (string) $finding->subject['model'],
                'verb' => '—',
                'object' => (string) $finding->subject['alias'],
                'grammar' => false,
                'icon' => false,
                'aggregates' => '—',
                'last_recorded' => null,
                'status' => 'unwired',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $lastRecorded
     * @param  array<string, list<string>>  $roleMap
     * @return array<string, mixed>
     */
    protected function row(
        StoryfeedManager $storyfeed,
        string $source,
        ?string $type,
        string $verb,
        array $lastRecorded,
        array $roleMap,
    ): array {
        $key = ($type ?? '*').'.'.$verb;

        $grammar = $storyfeed->templateKey($type, $verb) !== null;
        $icon = $storyfeed->iconKey($type, $verb) !== null;
        $recorded = $lastRecorded[$key] ?? null;

        // Applicable axes are DERIVED from the recipes and the roles this verb
        // was observed filling — never from reasoning about which axes a verb
        // "can" produce, which has been wrong in practice.
        $applicable = $storyfeed->axesApplicableTo($roleMap[$verb] ?? []);
        $authored = array_filter(
            $applicable,
            fn (string $axis) => $storyfeed->aggregateTemplateKey($axis, $verb) !== null,
        );

        $aggregates = $applicable === [] ? '—' : count($authored).'/'.count($applicable);

        return [
            'source' => $source,
            'verb' => $verb,
            'object' => $type,
            'grammar' => $grammar,
            'icon' => $icon,
            'aggregates' => $aggregates,
            'last_recorded' => $recorded,
            'status' => $this->status($grammar, $recorded, $applicable, $authored),
        ];
    }

    /**
     * Five statuses, each mechanically defined — no judgement calls.
     *
     * @param  array<int, string>  $applicable
     * @param  array<int, string>  $authored
     */
    protected function status(bool $grammar, ?string $recorded, array $applicable, array $authored): string
    {
        if (! $grammar) {
            return $recorded === null ? 'unwired' : 'unauthored';
        }

        if ($recorded === null) {
            return 'dead';
        }

        if (count($authored) < count($applicable)) {
            return 'gap';
        }

        return 'ok';
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function summary(array $rows): void
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        ksort($counts);

        $parts = [count($rows).' stories'];

        foreach ($counts as $status => $count) {
            $parts[] = "{$count} {$status}";
        }

        $this->newLine();
        $this->line(implode(' · ', $parts));

        $newest = collect($rows)->pluck('last_recorded')->filter()->max();

        if ($newest !== null) {
            $days = (int) Carbon::parse($newest)->diffInDays(Carbon::now());

            if ($days >= (int) $this->option('since')) {
                $this->warn("Nothing has been recorded in {$days} days — the feed may have stopped growing.");
            }
        }

        if (($counts['unauthored'] ?? 0) > 0 || ($counts['gap'] ?? 0) > 0) {
            $this->line('Author the missing keys: php artisan storyfeed:doctor --stubs');
        }
    }

    /** @return array<string, string> "type.verb" => ISO timestamp */
    protected function lastRecordedByPair(): array
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $rows = $model::query()
            ->toBase()
            ->selectRaw('object_type, verb, max(published_at) as last_at')
            ->groupBy('object_type', 'verb')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[($row->object_type ?? '*').'.'.$row->verb] = (string) $row->last_at;
        }

        return $map;
    }

    /** @return array<string, list<string>> */
    protected function roleMap(): array
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $rows = $model::query()
            ->distinct()
            ->toBase()
            ->get(['verb', 'actor_type', 'object_type', 'target_type', 'context_type']);

        $map = [];

        foreach ($rows as $row) {
            $map[$row->verb] ??= [];

            foreach (['actor', 'object', 'target', 'context'] as $role) {
                if ($row->{"{$role}_type"} !== null) {
                    $map[$row->verb][$role] = true;
                }
            }
        }

        return array_map(fn (array $roles) => array_keys($roles), $map);
    }
}
