<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Snapshot;

/**
 * A role with no model behind it: `['type' => 'vote', 'label' => 'their vote']`,
 * optionally with a `url`, an `id`, `data` and a `body`. The same shape the
 * array source reads, accepted on the write path too.
 *
 * It is stored inline with the activity, in its `entities` column, and its
 * type and id in the role's own columns. Nothing hydrates or refreshes it:
 * the label it was recorded with is the label it keeps. An id makes it a
 * participant `involving()` can find; without one it is in no index.
 */
final class InlineEntity
{
    /** The keys an entity array may carry. */
    public const KEYS = ['type', 'label', 'url', 'id', 'data', 'body'];

    /**
     * @param  array<array-key, mixed>  $entity
     *
     * @throws InvalidArgumentException naming the role and the key at fault
     */
    public static function assert(string $role, array $entity): void
    {
        if (($unknown = array_diff(array_keys($entity), self::KEYS)) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown key [%s] on the [%s] entity. Entities take: %s.', implode(', ', $unknown), $role, implode(', ', self::KEYS),
            ));
        }

        foreach (['type', 'label'] as $required) {
            if (! is_string($entity[$required] ?? null) || $entity[$required] === '') {
                throw new InvalidArgumentException("The [{$role}] entity needs a [{$required}].");
            }
        }

        if (isset($entity['url']) && ! is_string($entity['url'])) {
            throw new InvalidArgumentException("The [{$role}] entity's [url] must be a string.");
        }
    }

    /**
     * The entity as it is stored: what the snapshot is rebuilt from at read
     * time. The id is the role's `{role}_id`, so it must fit that column.
     *
     * @param  array<array-key, mixed>  $entity
     * @return array{type: string, id: string|null, label: string, url?: string, data?: array<string, mixed>, body?: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when it is not an entity, or its id does not fit
     */
    public static function store(string $role, array $entity): array
    {
        self::assert($role, $entity);

        $id = $entity['id'] ?? null;

        if ($id !== null && (! is_scalar($id) || preg_match('/^[\x21-\x7E]{1,36}$/', (string) $id) !== 1)) {
            throw new InvalidArgumentException("The [{$role}] entity's [id] must be up to 36 printable ASCII characters, as a morph key is.");
        }

        $snapshot = FeedEntity::make(label: $entity['label'], data: $entity['data'] ?? [], body: $entity['body'] ?? null);

        return array_filter([
            'type' => $entity['type'],
            'id' => $id === null ? null : (string) $id,
            'label' => $entity['label'],
            'url' => $entity['url'] ?? null,
            'data' => $snapshot->data,
            'body' => $snapshot->body,
        ], fn (mixed $value, string $key) => in_array($key, ['type', 'id', 'label'], true) || ($value !== null && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** @var array<string, bool> */
    private static array $installed = [];

    /**
     * Whether the activities table has the `entities` column, by connection.
     * A query that looks inside it checks first, so an app that has not run
     * the migration reads as before.
     */
    public static function installed(Model $activity): bool
    {
        $key = $activity->getConnectionName().'|'.$activity->getTable();

        return self::$installed[$key] ??= $activity->getConnection()->getSchemaBuilder()->hasColumn($activity->getTable(), 'entities');
    }

    /**
     * Leave out the rows whose `$role` is an inline entity, for a query that
     * asks about the models behind a role.
     *
     * @template TBuilder of Builder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function exclude(Builder|QueryBuilder $query, Model $activity, string $role): Builder|QueryBuilder
    {
        return self::installed($activity) ? $query->whereNull("entities->{$role}") : $query;
    }

    /**
     * The snapshot a stored entity reads as: never saved, never refreshed.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function snapshot(array $stored): Snapshot
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);

        return (new $model)->forceFill([
            'model_type' => (string) ($stored['type'] ?? ''),
            'model_id' => isset($stored['id']) ? (string) $stored['id'] : null,
            'label' => is_string($stored['label'] ?? null) ? $stored['label'] : null,
            'data' => is_array($stored['data'] ?? null) ? $stored['data'] : [],
            'body' => is_array($stored['body'] ?? null) && $stored['body'] !== [] ? $stored['body'] : null,
            'meta' => is_string($stored['url'] ?? null) ? ['url' => $stored['url']] : [],
        ]);
    }
}
