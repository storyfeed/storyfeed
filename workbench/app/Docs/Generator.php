<?php

namespace Workbench\App\Docs;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Sources\ArraySource;

/**
 * The docs world's feeds, generated: each read records its rows into an empty
 * database through {@see World} and reads them back with the calls the docs
 * teach, `->log()` and `->live()`.
 *
 * One field is rewritten on the way out: an activity node's `id` is its row's
 * id (`j84`), not the database's, because the docs key every node by row.
 *
 * @phpstan-import-type Pack from World
 * @phpstan-import-type Row from World
 */
final class Generator
{
    /** One page holds a whole world: more than any pack's rows. */
    protected const PAGE = 100_000;

    /** @param  Pack  $pack */
    public function __construct(public readonly array $pack) {}

    /**
     * Every row's Log node, keyed by row id, in core's order: what the docs
     * commit as `worlds/<pack>/payloads.json`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function payloads(): array
    {
        $world = $this->world(array_column($this->pack['rows'], 'id'));

        return array_column($this->read($world, Storyfeed::feed()->log()), null, 'id');
    }

    /**
     * Each named scene's Live page, recorded on its own.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function live(): array
    {
        return array_map(fn (array $ids) => $this->read($this->world($ids), Storyfeed::feed()->live()), $this->pack['scenes']);
    }

    /**
     * Each named scene's Live page through the array source, as the docs read
     * it today (scripts/payloads/read.php), with no group headlines registered:
     * the baseline live() is compared with.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function liveFromArrays(): array
    {
        $world = new World($this->pack);
        Harness::boot();
        $world->open(groups: false);

        return array_map(fn (array $ids) => $this->decode(Storyfeed::feed()
            ->source(new ArraySource(array_map(fn (string $id) => $this->item($world->row($id)), $ids)))
            ->live()->limit(self::PAGE)->get()->toArray()), $this->pack['scenes']);
    }

    /**
     * The rows a page rebuilds by hand today, recorded for real.
     *
     *   deletion   the `delete` activity Recording Deletions draws: the row's
     *              object deleted after it, so the node reads its tombstone
     *   composite  the tasks Composites draws: one activity with `objects()`,
     *              in Live (the composite) and Log (its members)
     *
     * @return array{deletion: array<string, mixed>, composite: array{live: list<array<string, mixed>>, log: list<array<string, mixed>>}}
     */
    public function variants(): array
    {
        return ['deletion' => $this->deletion(), 'composite' => $this->composite()];
    }

    /**
     * The deletion row told with the verb `delete`, its object deleted at the
     * same moment, read in Log.
     *
     * @return array<string, mixed>
     */
    protected function deletion(): array
    {
        $world = $this->world([]);
        $row = ['verb' => 'delete'] + $world->row($this->pack['variants']['deletion']);
        $world->publish($row, $world->activity($row));

        Carbon::setTestNow(Carbon::parse($row['at']));
        $world->model($row['object'] ?? throw new InvalidArgumentException("Row [{$row['id']}] has no object to delete."))->delete();
        Carbon::setTestNow(Carbon::parse($this->pack['canonicalNow']));

        return $this->read($world, Storyfeed::feed()->log())[0];
    }

    /**
     * The composite rows told as one activity: the first row's actor and
     * target, every row's object, at the last row's time.
     *
     * @return array{live: list<array<string, mixed>>, log: list<array<string, mixed>>}
     */
    protected function composite(): array
    {
        $world = $this->world([]);
        $rows = array_map($world->row(...), $this->pack['variants']['composite']);
        $objects = array_map(fn (array $row) => $world->model($row['object'] ?? throw new InvalidArgumentException("Row [{$row['id']}] has no object.")), $rows);

        $pending = Storyfeed::activity($rows[0]['verb'])
            ->actor($world->model($rows[0]['actor'] ?? throw new InvalidArgumentException('A composite needs an actor.')))
            ->objects($objects)
            ->publishedAt(Carbon::parse(end($rows)['at']));
        if (isset($rows[0]['target'])) {
            $pending->target($world->model($rows[0]['target']));
        }
        $world->publish(['id' => 'composite'] + $rows[0], $pending);

        // Each member is an activity of its own: named by the row whose object it holds.
        foreach ($rows as $i => $row) {
            $world->tells((string) Activity::query()->where('object_type', $objects[$i]->getMorphClass())->where('object_id', $objects[$i]->getKey())->value('uid'), $row['id']);
        }

        return ['live' => $this->read($world, Storyfeed::feed()->live()), 'log' => $this->read($world, Storyfeed::feed()->log())];
    }

    /**
     * A fresh app with these rows recorded.
     *
     * @param  list<string>  $ids
     */
    protected function world(array $ids): World
    {
        $world = new World($this->pack);
        Harness::boot();
        $world->open();
        $world->record($ids);

        return $world;
    }

    /**
     * A whole feed, as plain arrays, with each activity named by its row.
     *
     * @return list<array<string, mixed>>
     */
    protected function read(World $world, FeedBuilder $feed): array
    {
        return $this->relabel($this->decode($feed->limit(self::PAGE)->get()->toArray()), $world->recorded);
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    protected function decode(array $nodes): array
    {
        return json_decode(json_encode($nodes, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @template T of array
     *
     * @param  T  $value
     * @param  array<string, string>  $rows
     * @return T
     */
    protected function relabel(array $value, array $rows): array
    {
        if (($value['kind'] ?? null) === 'activity' && is_string($value['id'] ?? null) && isset($rows[$value['id']])) {
            $value['id'] = $rows[$value['id']];
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->relabel($item, $rows);
            }
        }

        return $value;
    }

    /**
     * A row as an array-source item, as scripts/payloads.mjs writes it.
     *
     * @param  Row  $row
     * @return array<string, mixed>
     */
    protected function item(array $row): array
    {
        $item = ['id' => $row['id'], 'verb' => $row['verb'], 'published_at' => $row['at']];

        foreach (World::ROLES as $role) {
            if (isset($row[$role])) {
                $entity = $row[$role];
                $item[$role] = array_filter([
                    'type' => $entity['type'], 'id' => $entity['id'], 'label' => $entity['label'], 'url' => $entity['url'],
                    'data' => $entity['data'], 'body' => $entity['body'], 'media' => $entity['media'],
                ], fn (mixed $value) => $value !== null);
            }
        }

        if ($row['data'] !== null) {
            $item['data'] = $row['data'];
        }

        return $item;
    }
}
