<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Models\Snapshot;
use Throwable;

/** Walk a model's declared parent identities. Reads never traverse this chain. */
final class Ancestors
{
    public const ROLES = ['object', 'target', 'context'];

    /** @return array{type: string|null, id: int|string|null} */
    public static function identity(Model $model): array
    {
        return ['type' => app(Feedables::class)->morphAlias($model), 'id' => $model->getKey()];
    }

    /**
     * Current mode is for an explicit history rebuild, not a normal publish.
     *
     * @return array{rows: list<array{entity_type: string, entity_id: string, distance: int}>, unresolved: array<string, mixed>|null, has_parent: bool}
     */
    public function walk(string $type, int|string $id, ?int $snapshotId = null, bool $current = false): array
    {
        $rows = [];
        $seen = [$type."\0".$id => true];
        $unresolved = null;
        $hasParent = false;
        // Stored distances fit the unsigned tinyint even with a larger app cap.
        $cap = max(0, min(255, (int) config('storyfeed.ancestors.max_depth', 10)));
        try {
            $parent = $this->parent($type, $id, $snapshotId, $current);
            $hasParent = $parent !== null;
            for ($distance = 1; $parent !== null && $distance <= $cap; $distance++) {
                $parentType = $parent['type'] ?? null;
                $parentId = $parent['id'] ?? null;
                if (! is_string($parentType) || (! is_int($parentId) && ! is_string($parentId))) {
                    $unresolved = $parent;
                    break;
                }
                $identity = $parentType."\0".$parentId;
                if (isset($seen[$identity])) {
                    break;
                }
                $seen[$identity] = true;
                // Even a cached parent must still resolve: a missing or scoped
                // model ends the chain, while the activity itself stays visible.
                $model = MorphResolver::feedable($parentType, $parentId);
                if ($model === null) {
                    $unresolved = $parent;
                    break;
                }
                $rows[] = ['entity_type' => $parentType, 'entity_id' => (string) $parentId, 'distance' => $distance];
                $parent = $this->parent($parentType, $parentId, null, $current, $model);
            }
        } catch (Throwable) {
            // App scopes, parent relationships and external registrations can
            // fail independently. Keep the valid prefix and never hide a write.
            $unresolved = $parent ?? ['type' => $type, 'id' => $id];
        }

        return ['rows' => $rows, 'unresolved' => $unresolved, 'has_parent' => $hasParent];
    }

    /** @return array<string, mixed>|null */
    private function parent(string $type, int|string $id, ?int $snapshotId, bool $current, ?Model $model = null): ?array
    {
        if (! $current) {
            $snapshot = config('storyfeed.models.snapshot', Snapshot::class);
            $row = $snapshot::query()->where('model_type', $type)->where('model_id', MorphKeyType::value($id))
                ->when($snapshotId !== null, fn ($query) => $query->whereKey($snapshotId))->first();
            // The root was snapshotted by publish. Intermediate snapshots
            // may predate parent() entirely: absence there is not evidence
            // that today's model is a terminal container.
            if ($row !== null && ($snapshotId !== null || array_key_exists('parent', $row->meta ?? []))) {
                return $row->meta['parent'] ?? null;
            }
        }
        $model ??= MorphResolver::feedable($type, $id);
        if ($model === null) {
            return null;
        }
        $parent = app(Feedables::class)->toFeed($model)->parent;

        return $parent === null ? null : self::identity($parent);
    }
}
