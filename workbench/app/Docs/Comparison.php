<?php

namespace Workbench\App\Docs;

/**
 * What the generator changes, against what the docs show today: the committed
 * payloads.json for each row's Log node, and the array source's Live read for
 * each scene. Each difference is sorted into what it is, so a reader sees
 * the kinds of change rather than a diff of half a megabyte of JSON.
 *
 * @phpstan-import-type Pack from World
 */
final class Comparison
{
    /** @var list<string> */
    protected array $lines = [];

    protected bool $differs = false;

    /** @param  Pack  $pack */
    public function __construct(protected string $name, protected array $pack) {}

    public function differs(): bool
    {
        return $this->differs;
    }

    public function report(): string
    {
        return implode("\n", $this->lines)."\n";
    }

    /**
     * @param  array<string, array<string, mixed>>  $generated
     * @param  array<string, array<string, mixed>>  $committed
     */
    public function payloads(array $generated, array $committed, bool $identical): void
    {
        $this->differs = $this->differs || ! $identical;
        $this->lines[] = "# {$this->name}";
        $this->lines[] = '';
        $this->lines[] = sprintf('## payloads.json (Log): %d nodes generated, %d committed%s', count($generated), count($committed), $identical ? ', byte for byte the same' : '');
        $this->lines[] = '';

        $rows = array_column($this->pack['rows'], null, 'id');
        $future = $missing = [];
        foreach (array_diff_key($committed, $generated) as $id => $node) {
            if (($rows[$id]['at'] ?? '') > $this->pack['canonicalNow']) {
                $future[] = $id;
            } else {
                $missing[] = $id;
            }
        }

        $this->item($future, 'Left out: published after the pack\'s now (%s), and core\'s Log reads no future', $this->pack['canonicalNow']);
        $this->item($missing, 'Missing, with no reason found');
        $this->item(array_keys(array_diff_key($generated, $committed)), 'New nodes the docs do not have');

        $shared = array_keys(array_intersect_key($generated, $committed));
        $inOrder = array_values(array_intersect(array_keys($committed), $shared));
        if (array_values(array_intersect(array_keys($generated), $shared)) !== $inOrder) {
            $this->lines[] = '- **Order differs** from the committed file.';
        }

        $parties = $other = [];
        foreach ($shared as $id) {
            foreach ($this->fields($generated[$id], $committed[$id]) as $path => [$new, $old]) {
                $role = explode('.', $path)[0];
                if (($generated[$id][$role]['type'] ?? null) === 'storyfeed.party') {
                    $parties[preg_replace('/^\w+\./', '', $path)][$id] = [$old, $new];
                } else {
                    $other["{$id} {$path}"] = [$old, $new];
                }
            }
        }

        if ($parties !== []) {
            $nodes = array_unique(array_merge(...array_map(array_keys(...), array_values($parties))));
            $this->lines[] = sprintf('- **Parties are real `Party` rows** (%d nodes: %s):', count($nodes), implode(', ', $nodes));
            foreach ($parties as $path => $changes) {
                $pairs = array_unique(array_map(fn (array $pair) => $this->json($pair[0]).' → '.$this->json($pair[1]), $changes));
                $this->lines[] = "  - `{$path}`: ".implode('; ', $pairs);
            }
        }

        foreach ($other as $where => [$old, $new]) {
            $this->lines[] = "- `{$where}`: {$this->json($old)} → {$this->json($new)}";
        }

        if ($future === [] && $missing === [] && $parties === [] && $other === []) {
            $this->lines[] = '- No differences.';
        }

        $this->lines[] = '';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $generated
     * @param  array<string, list<array<string, mixed>>>  $baseline
     */
    public function live(array $generated, array $baseline): void
    {
        $same = $headlines = $grouping = $ties = [];

        foreach ($generated as $scene => $nodes) {
            $new = array_map($this->shape(...), $nodes);
            $old = array_map($this->shape(...), $baseline[$scene] ?? []);

            if ($this->canonical($new, $nodes) !== $this->canonical($old, $baseline[$scene] ?? [])) {
                $grouping[$scene] = [array_values(array_diff($old, $new)), array_values(array_diff($new, $old))];
            } elseif ($new !== $old) {
                $ties[] = $scene;
            }

            $changed = [];
            foreach ($nodes as $node) {
                if ($node['kind'] !== 'group') {
                    continue;
                }
                $before = $this->find($baseline[$scene] ?? [], $this->shape($node));
                if ($before !== null && $before['headline_template'] !== $node['headline_template']) {
                    $changed[] = sprintf('%s: %s → %s', $node['axis'], $this->json($before['headline_template']), $this->json($node['headline_template']));
                }
            }
            foreach (array_unique($changed) as $change) {
                $headlines[$change][] = $scene;
            }

            if (! isset($grouping[$scene]) && ! in_array($scene, $ties, true) && $changed === []) {
                $same[] = $scene;
            }
        }

        $this->lines[] = sprintf('## Live: %d scenes, each recorded on its own; %d unchanged', count($generated), count($same));
        $this->lines[] = '';
        $this->lines[] = 'Compared with the same rows read through the array source, as scripts/payloads/read.php does today (no group wording registered).';
        $this->lines[] = '';

        if ($headlines !== []) {
            $this->lines[] = '- **Group headlines from the pack\'s wording** (its `repeat`, `actors`, `object`… per verb, registered as `grouped()` grammar):';
            foreach ($headlines as $change => $scenes) {
                $this->lines[] = "  - {$change} (".implode(', ', array_map(fn (string $scene) => "`{$scene}`", $scenes)).')';
            }
        }

        foreach ($grouping as $scene => [$gone, $added]) {
            $this->lines[] = "- **Grouping differs** in `{$scene}`: ".implode('; ', array_merge(
                array_map(fn (string $shape) => "was {$shape}", $gone), array_map(fn (string $shape) => "now {$shape}", $added),
            ));
        }

        $this->item($ties, 'Same groups; items at one instant in another order (core breaks ties on its grouping hash)');
        $this->differs = $this->differs || $headlines !== [] || $grouping !== [];
        $this->lines[] = '';
    }

    /**
     * @param  array{deletion: array<string, mixed>, composite: array{live: list<array<string, mixed>>, log: list<array<string, mixed>>}}  $variants
     */
    public function variants(array $variants): void
    {
        $deletion = $variants['deletion'];
        $tombstone = $deletion['object']['tombstone'] ?? null;
        $composite = $variants['composite']['live'][0] ?? null;

        $this->lines[] = '## Rows a page builds by hand today, now recorded';
        $this->lines[] = '';
        $this->lines[] = sprintf(
            '- **Recording Deletions** (`%s`, verb `delete`): the object is deleted after the activity and reads as a tombstone: type %s, formerType %s, deleted %s, label %s. Headline: %s.',
            $deletion['id'], $this->json($deletion['object']['type'] ?? null), $this->json($tombstone['formerType'] ?? null),
            $this->json($tombstone['deleted'] ?? null), $this->json($deletion['object']['label'] ?? null), $this->headline($deletion, 'delete', 'headline'),
        );
        $this->lines[] = $composite === null ? '- **Composites**: no node.' : sprintf(
            '- **Composites** (%s, `objects()`): Live reads one %s group ×%d of %s; Log reads its members (%s). Headline: %s.',
            implode(', ', $this->pack['variants']['composite']), $this->json($composite['axis'] ?? null), $composite['count'] ?? 0,
            implode(', ', array_map(fn (array $object) => $this->json($object['label']), $composite['sample']['objects'] ?? [])),
            implode(', ', array_column($variants['composite']['log'], 'id')), $this->headline($composite, $composite['verb'] ?? '', 'composite'),
        );
        $this->lines[] = '';
    }

    /**
     * A node's headline, or why it has none.
     *
     * @param  array<string, mixed>  $node
     */
    protected function headline(array $node, string $verb, string $key): string
    {
        return $node['headline_template'] !== null
            ? $this->json($node['headline_template'])
            : "none, because the pack gives `{$verb}` no `{$key}` wording";
    }

    /**
     * Every leaf that differs, by dotted path: [generated, committed].
     *
     * @return array<string, array{mixed, mixed}>
     */
    protected function fields(mixed $new, mixed $old, string $path = ''): array
    {
        if (is_array($new) && is_array($old) && (array_is_list($new) === array_is_list($old)) && (! array_is_list($new) || count($new) === count($old))) {
            $found = [];
            foreach (array_keys($new + $old) as $key) {
                $found += $this->fields($new[$key] ?? null, $old[$key] ?? null, ltrim("{$path}.{$key}", '.'));
            }

            return $found;
        }

        return $new === $old ? [] : [$path => [$new, $old]];
    }

    /**
     * What a page shows of a node: which activities, folded how.
     *
     * @param  array<string, mixed>  $node
     */
    protected function shape(array $node): string
    {
        if ($node['kind'] !== 'group') {
            return "activity {$node['id']}";
        }

        $members = array_column($node['children'], 'id');
        sort($members);

        return "group {$node['axis']} ×{$node['count']} [".implode(' ', $members).']';
    }

    /**
     * Shapes newest first, items at one instant in a fixed order of their own.
     *
     * @param  list<string>  $shapes
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    protected function canonical(array $shapes, array $nodes): array
    {
        $keyed = array_map(fn (string $shape, array $node) => [$node['published_at'], $shape], $shapes, $nodes);
        usort($keyed, fn (array $a, array $b) => strcmp($b[0], $a[0]) ?: strcmp($a[1], $b[1]));

        return array_column($keyed, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    protected function find(array $nodes, string $shape): ?array
    {
        foreach ($nodes as $node) {
            if ($this->shape($node) === $shape) {
                return $node;
            }
        }

        return null;
    }

    /** @param  list<string>  $ids */
    protected function item(array $ids, string $what, string ...$values): void
    {
        if ($ids !== []) {
            $this->lines[] = '- **'.sprintf($what, ...$values).'** ('.count($ids).'): '.implode(', ', $ids);
        }
    }

    protected function json(mixed $value): string
    {
        return '`'.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'`';
    }
}
