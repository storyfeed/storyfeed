<?php

namespace Storyfeed\Actions;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\PendingTombstone;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\SyncToken;
use Storyfeed\Support\TombstoneRules;
use Throwable;

/**
 * Point the feed at a tombstone for a deleted entity. What a Feedable's
 * `deleted` and `forceDeleted` events do, whether the model uses
 * InteractsWithFeed or was registered with `Storyfeed::feedable()`; what
 * `Storyfeed::tombstone()` does after a bulk delete; and what the trickle
 * does when it finds a deletion nothing reported.
 *
 * It creates the tombstone (or reuses the one the entity already has),
 * snapshots it, repoints every reference to the entity onto it (see
 * RepointReferences), and deletes the entity's own snapshot, so its real
 * label doesn't stay in the database. `sync_token` is bumped once when any
 * activity moved, because settled history was rewritten.
 *
 * THIS REPLACED THE CASCADE. Until 2026-09-23 a model's `deleted` event
 * soft-deleted every activity involving it, and its `forceDeleted` event
 * deleted them outright. The stories now survive the deletion, told about "a
 * removed order". Deleting the activities themselves is still available on
 * purpose: `deleteFromFeed()` and `forceDeleteFromFeed()`.
 */
class TombstoneEntity
{
    private static bool $installed = false;

    /**
     * The event path. A soft delete leaves a restorable tombstone; a hard
     * delete (a model without SoftDeletes, or a force delete) leaves a
     * permanent one.
     */
    public function __invoke(Model $model): ?FeedTombstone
    {
        if (! self::installed()) {
            return null;
        }

        $forcing = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
        $trashedAt = $forcing ? null : self::trashedAt($model);

        return $this->unlessUnnamed($this->reference(
            $model->getMorphClass(),
            $model->getKey(),
            restorable: $trashedAt !== null,
            deletedAt: $trashedAt,
            label: $this->keptLabel($model),
        ));
    }

    /**
     * A soft-deleted model was force-deleted: its tombstone becomes
     * permanent. Created if the model's deletion was never heard.
     */
    public function forceDeleted(Model $model): ?FeedTombstone
    {
        if (! self::installed()) {
            return null;
        }

        return $this->unlessUnnamed(
            $this->reference($model->getMorphClass(), $model->getKey(), restorable: false, label: $this->keptLabel($model)),
        );
    }

    /**
     * On the model-event paths, a tombstone no activity names is not kept,
     * as a prune run leaves things: the model was never in the feed, or a
     * soft-deletable model's `forceDeleted` followed its `deleted`, whose
     * `->forgetWhenMissing()` had already taken the last row naming it and
     * the tombstone with it. An explicit `Storyfeed::tombstone()` keeps what
     * it was asked to make.
     */
    protected function unlessUnnamed(FeedTombstone $tombstone): FeedTombstone
    {
        (new PurgeActivities)->sweep([$tombstone->getMorphClass() => [(string) $tombstone->getKey() => true]]);

        return $tombstone;
    }

    /**
     * Tombstone deleted rows by alias and keys, without their models: the
     * explicit call after a bulk delete, and the trickle's discovery. Keys
     * whose row still exists and isn't trashed are skipped. An alias that is
     * not a model class (a non-model Feedable) has no rows to check, so every
     * key is tombstoned, and never restorably.
     *
     * Existence is checked WITHOUT GLOBAL SCOPES, so a row hidden by a tenant
     * scope is never mistaken for a deleted one.
     *
     * @param  iterable<int|string>  $ids
     * @return list<FeedTombstone>
     */
    public function missing(string $alias, iterable $ids, bool $approximate = false): array
    {
        $ids = array_values(array_unique(array_map(fn ($id) => (string) $id, [...$ids])));

        if ($ids === []) {
            return [];
        }

        $class = MorphResolver::classFor($alias);
        /** @var array<string, Model> $rows */
        $rows = [];

        if ($class !== null && is_a($class, Model::class, true)) {
            $model = new $class;

            foreach ($class::query()->withoutGlobalScopes()->whereIn($model->getQualifiedKeyName(), $ids)->get() as $row) {
                $rows[(string) $row->getKey()] = $row;
            }
        }

        $tombstones = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $trashedAt = $row === null ? null : self::trashedAt($row);

            if ($row !== null && $trashedAt === null) {
                continue;
            }

            // A trashed row knows exactly when it went.
            $tombstones[] = $this->reference(
                $alias,
                $row?->getKey() ?? $id,
                restorable: $trashedAt !== null,
                approximate: $approximate && $trashedAt === null,
                deletedAt: $trashedAt,
            );
        }

        return $tombstones;
    }

    /**
     * Create or reuse the tombstone for an alias and key, and move the feed
     * onto it. Idempotent: a second call finds nothing left to move. A
     * permanent tombstone never becomes restorable again.
     *
     * `$label` is the model's label, kept on the tombstone when its entity
     * asked (`keepLabel()`). Once the tombstone is permanent, the activities
     * it made redundant are deleted where their verb asked
     * (`->forgetWhenMissing()`), whichever path made it: a model event, a
     * bulk delete's `Storyfeed::tombstone()`, or the trickle.
     */
    public function reference(
        string $alias,
        int|string $id,
        bool $restorable,
        bool $approximate = false,
        ?DateTimeInterface $deletedAt = null,
        ?string $label = null,
    ): FeedTombstone {
        $model = config('storyfeed.models.tombstone', FeedTombstone::class);

        /** @var FeedTombstone $tombstone */
        $tombstone = $model::query()->firstOrCreate(
            ['model_type' => $alias, 'model_id' => (string) $id],
            [
                'restorable' => $restorable,
                'approximate' => $approximate,
                'deleted_at' => $deletedAt ?? Carbon::now(),
            ],
        );

        if (! $tombstone->wasRecentlyCreated && ! $restorable && $tombstone->restorable) {
            $tombstone->forceFill(['restorable' => false])->save();
        }

        // Before the snapshot, which is where the label is read from.
        if ($label !== null && $tombstone->label === null) {
            $tombstone->forceFill(['label' => $label])->save();
        }

        $snapshot = (new SnapshotEntity)($tombstone);

        $moved = (new RepointReferences)($alias, $id, $tombstone->getMorphClass(), $tombstone->getKey(), $snapshot->getKey());

        // Privacy: the model's real label must not outlive the model, unless
        // the model asked for it to (keepLabel(), now on the tombstone).
        $snapshots = config('storyfeed.models.snapshot', Snapshot::class);
        $snapshots::query()->where('model_type', $alias)->where('model_id', $id)->delete();

        // Only a permanent tombstone forgets: a soft delete must stay
        // undoable by a restore.
        $forgotten = ! $tombstone->restorable && app(TombstoneRules::class)->forgetsAny()
            ? $this->forgetRedundant($tombstone)
            : 0;

        if ($moved > 0 || $forgotten > 0) {
            SyncToken::bump();
        }

        return $tombstone;
    }

    /**
     * The label the model's entity asked its tombstone to keep
     * (`FeedEntity::tombstone()` → `keepLabel()`), or null.
     *
     * A model whose toFeed() fails while it is being deleted (a relation
     * already gone) must not fail the delete, so the failure is reported
     * and the tombstone keeps nothing.
     */
    protected function keptLabel(Model $model): ?string
    {
        $feedables = app(Feedables::class);

        if (! $feedables->isFeedable($model)) {
            return null;
        }

        try {
            $entity = $feedables->toFeed($model);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($entity->tombstone === null) {
            return null;
        }

        ($entity->tombstone)($pending = new PendingTombstone);

        return $pending->keepsLabel() ? $entity->label : null;
    }

    /**
     * Delete, through PurgeActivities, the activities where the tombstone
     * fills a role their verb is about and whose verb forgets
     * (TombstoneRules), and nothing else.
     *
     * The rules are asked with the activity's object type, which for an
     * activity whose object is a tombstone means the type it was: after the
     * repoint, `order.place` rows read `storyfeed.tombstone`.
     */
    protected function forgetRedundant(FeedTombstone $tombstone): int
    {
        $alias = $tombstone->getMorphClass();
        $id = $tombstone->getKey();
        $rules = app(TombstoneRules::class);

        $involving = fn () => DeleteFromFeed::query()->withTrashed()->where(function ($query) use ($alias, $id) {
            foreach (ActivityRoles::STORED as $role) {
                $query->orWhere(fn ($query) => $query->where("{$role}_type", $alias)->where("{$role}_id", $id));
            }
        });

        $pairs = [];

        $live = $involving()->where(fn ($query) => $query->whereNull('object_type')->orWhere('object_type', '!=', $alias))
            ->toBase()->select('object_type', 'verb')->distinct()->get();

        foreach ($live as $row) {
            $type = $row->object_type;
            $pairs[] = [
                fn ($query) => $type === null ? $query->whereNull('object_type') : $query->where('object_type', $type),
                (string) $row->verb,
                $rules->forgets($type, (string) $row->verb) ? $rules->constitutiveRoles($type, (string) $row->verb) : [],
            ];
        }

        $gone = $involving()->where('object_type', $alias)->toBase()->select('object_id', 'verb')->distinct()->get();

        if ($gone->isNotEmpty()) {
            $model = config('storyfeed.models.tombstone', FeedTombstone::class);
            $formerTypes = $model::query()->whereKey($gone->pluck('object_id')->unique()->all())->pluck('model_type', 'id')->all();

            foreach ($gone as $row) {
                $objectId = $row->object_id;
                $formerType = $formerTypes[$objectId] ?? null;
                $pairs[] = [
                    fn ($query) => $query->where('object_type', $alias)->where('object_id', $objectId),
                    (string) $row->verb,
                    $rules->forgets($formerType, (string) $row->verb) ? $rules->constitutiveRoles($formerType, (string) $row->verb) : [],
                ];
            }
        }

        $pairs = array_filter($pairs, fn (array $pair) => $pair[2] !== []);

        if ($pairs === []) {
            return 0;
        }

        // Through PurgeActivities, as a prune run deletes: the groups these
        // rows sat in are repaired, and a snapshot or tombstone nothing else
        // names goes with them, this tombstone included.
        return (new PurgeActivities)(fn () => $involving()->where(function ($query) use ($pairs, $alias, $id) {
            foreach ($pairs as [$object, $verb, $roles]) {
                $query->orWhere(function ($query) use ($object, $verb, $roles, $alias, $id) {
                    $object($query);

                    $query->where('verb', $verb)->where(function ($query) use ($roles, $alias, $id) {
                        foreach ($roles as $role) {
                            $query->orWhere(fn ($query) => $query->where("{$role}_type", $alias)->where("{$role}_id", $id));
                        }
                    });
                });
            }
        }))['activities'];
    }

    /**
     * Whether the tombstones table exists. The model events check first, so
     * a deploy that runs its code before `migrate` doesn't turn every model
     * delete into an error: the delete goes unheard, `storyfeed:doctor`
     * reports the missing table, and the trickle finds the deletion later.
     * Only a positive answer is remembered.
     */
    public static function installed(): bool
    {
        if (self::$installed) {
            return true;
        }

        $model = config('storyfeed.models.tombstone', FeedTombstone::class);

        return self::$installed = Schema::connection((new $model)->getConnectionName())->hasTable((new $model)->getTable());
    }

    /**
     * When a soft-deleted model was trashed. Null for a model that isn't
     * trashed, or can't be.
     */
    public static function trashedAt(Model $model): ?DateTimeInterface
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return null;
        }

        $value = $model->getAttribute($model->getDeletedAtColumn());

        return $value === null || $value instanceof DateTimeInterface ? $value : Carbon::parse($value);
    }
}
