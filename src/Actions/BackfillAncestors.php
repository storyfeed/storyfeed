<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\Ancestors;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MorphKeyType;
use Storyfeed\Support\MorphResolver;

/** Fill never-recorded role paths; existing participant rows are immutable. */
final class BackfillAncestors
{
    /** @return list<string> */
    public function missingRoles(Activity $activity): array
    {
        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);
        $meta = config('storyfeed.models.meta', Meta::class);
        $roles = [];
        foreach (Ancestors::ROLES as $role) {
            $type = $activity->getAttribute("{$role}_type");
            $id = $activity->getAttribute("{$role}_id");
            if ($type === null || $id === null || $meta::query()->useWritePdo()->where('key', $this->key($activity, $role))->exists()) {
                continue;
            }
            $snapshotId = $activity->getAttribute("cached_{$role}_id");
            $row = $snapshotId === null ? null : $snapshot::query()->useWritePdo()->whereKey($snapshotId)
                ->where('model_type', $type)->where('model_id', MorphKeyType::value($id))->first();
            // A null parent is positive evidence too. No ancestor rows alone
            // cannot tell a recorded terminal role from a never-walked role.
            if ($row !== null && array_key_exists('parent', $row->meta ?? [])) {
                continue;
            }
            $model = MorphResolver::feedable($type, $id);
            if ($model !== null && app(Feedables::class)->toFeed($model)->parentDeclared) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function __invoke(Activity $activity): void
    {
        $connection = $activity->getConnection();
        $connection->transaction(function () use ($activity, $connection): void {
            $roles = $this->missingRoles($activity);
            $rows = [];
            $direct = [];
            foreach (SyncParticipants::ROLES as $role) {
                $direct[$activity->getAttribute("{$role}_type")."\0".$activity->getAttribute("{$role}_id")] = true;
            }
            foreach ($roles as $role) {
                $path = (new Ancestors)->walk($activity->getAttribute("{$role}_type"), $activity->getAttribute("{$role}_id"), current: true);
                foreach ($path['rows'] as $ancestor) {
                    $identity = $ancestor['entity_type']."\0".$ancestor['entity_id'];
                    if (isset($direct[$identity]) || (isset($rows[$identity]) && $rows[$identity]['distance'] <= $ancestor['distance'])) {
                        continue;
                    }
                    $rows[$identity] = array_merge($ancestor, [
                        'activity_id' => $activity->getKey(), 'role' => 'ancestor',
                        'published_at' => $activity->published_at === null ? null : Chronology::stamp($activity->published_at),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($rows !== []) {
                $connection->table(SyncParticipants::table())->insertOrIgnore(array_values($rows));
            }
            // Remember each completed role, including an empty walk. Shared
            // snapshots must not be changed: other activities can still have
            // gaps against the same snapshot. A later move must not append a
            // different path on rerun, even for a role with no snapshot.
            $meta = new (config('storyfeed.models.meta', Meta::class));
            foreach ($roles as $role) {
                $connection->table($meta->getTable())->insertOrIgnore([
                    'key' => $this->key($activity, $role), 'value' => 'recorded',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function key(Activity $activity, string $role): string
    {
        return 'backfill:ancestors:role:'.hash('sha256', serialize([
            $activity->uid, $role, $activity->getAttribute("{$role}_type"), $activity->getAttribute("{$role}_id"),
        ]));
    }
}
