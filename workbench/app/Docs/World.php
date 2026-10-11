<?php

namespace Workbench\App\Docs;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\PendingActivity;

/**
 * One docs world pack, recorded the way an app records: each thing a row
 * names is a model ({@see WorldModel}) or a Party, and each row is published
 * through `Storyfeed::activity()`, with the pack's verb wording registered as
 * definitions. Time stands at the pack's `canonicalNow`.
 *
 * @phpstan-type Entity array{type: string, id: string, label: string, url: string|null, data: array<string, mixed>|null, body: list<array<string, mixed>>|null, media: array<string, mixed>|null}
 * @phpstan-type Row array{id: string, at: string, verb: string, data: array<string, mixed>|null, headline: string|null, actor?: Entity, object?: Entity, target?: Entity, context?: Entity, origin?: Entity, result?: Entity, instrument?: Entity, location?: Entity, generator?: Entity}
 * @phpstan-type Wording array{glyph: string, headline: string, repeat?: string, actors?: string, targets?: string, object?: string, actors_target?: string, composite?: string}
 * @phpstan-type Pack array{canonicalNow: string, verbs: array<string, Wording>, intents: array<string, string>, rows: list<Row>, scenes: array<string, list<string>>, variants: array{deletion: string, composite: list<string>}}
 */
final class World
{
    public const ROLES = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator'];

    /** A group headline per axis, as a pack's wording names it. */
    protected const AXES = ['repeat' => 'repeat', 'actors' => 'byActors', 'actors_target' => 'byActorsOnTarget', 'targets' => 'byTargets', 'object' => 'byObject', 'composite' => 'composite'];

    /** @var array<string, Row> */
    public readonly array $rows;

    /**
     * Which row each recorded activity tells, by activity id.
     *
     * @var array<string, string>
     */
    public private(set) array $recorded = [];

    /** @param  Pack  $pack */
    public function __construct(public readonly array $pack)
    {
        $this->rows = array_column($pack['rows'], null, 'id');
    }

    /**
     * Stop the clock at the pack's now and register its wording, in a freshly
     * booted app. Without `$groups`, only each verb's headline and glyph, as
     * the docs registered them before this generator (scripts/payloads/read.php).
     */
    public function open(bool $groups = true): void
    {
        Carbon::setTestNow(Carbon::parse($this->pack['canonicalNow']));

        foreach ($this->pack['verbs'] as $verb => $wording) {
            $grouped = [];
            foreach ($groups ? self::AXES : [] as $axis => $group) {
                // A group of actors at one target reads as a group of actors, as world.ts folds it.
                $headline = $wording[$axis] ?? ($axis === 'actors_target' ? $wording['actors'] ?? null : null);
                if ($headline !== null) {
                    $grouped[] = Group::{$group}()->headline($headline);
                }
            }

            Story::verb($verb)->headline($wording['headline'])->icon($wording['glyph'])->grouped(...$grouped);
        }

        foreach ($this->pack['intents'] as $key => $intent) {
            [$type, $verb] = explode('.', $key, 2);
            Story::for($type)->verb($verb)->intent($intent);
        }
    }

    /**
     * Publish these rows as they happened: oldest first, rows at one instant
     * in pack order. Bursts are assigned as activities arrive, so a world
     * published out of order would not group as an app's does.
     *
     * @param  list<string>  $ids
     */
    public function record(array $ids): void
    {
        $wanted = array_flip($ids);
        $rows = array_values(array_filter($this->pack['rows'], fn (array $row) => isset($wanted[$row['id']])));
        usort($rows, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        foreach ($rows as $row) {
            $this->publish($row, $this->activity($row));
        }
    }

    /**
     * A row as a pending activity: its verb, roles, data and time.
     *
     * @param  Row  $row
     */
    public function activity(array $row): PendingActivity
    {
        $pending = Storyfeed::activity($row['verb']);

        if (! isset($row['actor'])) {
            $pending->anonymously();
        }

        foreach (self::ROLES as $role) {
            if (isset($row[$role])) {
                $pending->{$role}($this->model($row[$role]));
            }
        }

        if ($row['data'] !== null) {
            $pending->data($row['data']);
        }

        return $pending->publishedAt(Carbon::parse($row['at']));
    }

    /**
     * Publish a pending activity as this row's.
     *
     * @param  Row  $row
     */
    public function publish(array $row, PendingActivity $pending): Activity
    {
        $activity = $pending->publish();
        $this->tells((string) $activity->uid, $row['id']);

        return $activity;
    }

    /** Name an activity by the row it tells. */
    public function tells(string $uid, string $row): void
    {
        $this->recorded[$uid] = $row;
    }

    /**
     * The model behind an entity: created the first time the world names it.
     *
     * @param  Entity  $entity
     */
    public function model(array $entity): WorldModel|Party
    {
        if ($entity['type'] === 'storyfeed.party') {
            // Party::make() finds or creates by name; it is not Model::make().
            return Party::make($entity['label']); // @phpstan-ignore larastan.noModelMake
        }

        $class = WorldModel::define($entity['type']);
        $class::migrate();

        return $class::query()->firstOrCreate(['id' => $entity['id']], [
            'label' => $entity['label'],
            'url' => $entity['url'],
            'data' => $entity['data'],
            'body' => $entity['body'],
            'media' => $entity['media'],
        ]);
    }

    /** @return Row */
    public function row(string $id): array
    {
        return $this->rows[$id] ?? throw new InvalidArgumentException("The world has no row [{$id}].");
    }
}
