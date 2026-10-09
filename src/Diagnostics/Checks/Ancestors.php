<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Actions\BackfillAncestors;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Ancestors as Walker;

final class Ancestors extends Check
{
    public function name(): string
    {
        return 'ancestors';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('snapshots')) {
            return;
        }
        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);
        $walker = new Walker;
        foreach ($snapshot::query()->lazyById(200) as $row) {
            if (! isset($row->meta['parent'])) {
                continue;
            }
            $path = $walker->walk($row->model_type, $row->model_id, $row->getKey());
            if ($path['unresolved'] !== null) {
                yield Finding::warning('ancestors.unresolvable',
                    "The parent chain of `{$row->model_type}#{$row->model_id}` stops at an unresolvable parent. Restore the parent or correct parent() and run storyfeed:participants --ancestors --writers-paused to update history.",
                    ['type' => $row->model_type, 'id' => $row->model_id, 'parent' => json_encode($path['unresolved'])],
                );
            }
        }
        if (! $this->hasTable('activities')) {
            return;
        }
        if ($this->hasTable('meta')) {
            $backfill = new BackfillAncestors;
            foreach ($this->activities()->withTrashed()->lazyById(200) as $row) {
                foreach ($backfill->missingRoles($row) as $role) {
                    yield Finding::warning('ancestors.missing',
                        "Activity #{$row->getKey()} has no recorded {$role} parent path, but parent() is now declared. Pause readers and writers, then run storyfeed:participants --ancestors --missing --writers-paused to fill gaps while preserving recorded paths.",
                        ['activity_id' => $row->getKey(), 'role' => $role],
                    );
                }
            }
        }
        $query = $this->activities()->whereNull('object_type')->whereNull('target_type')->whereNull('context_type')->whereNotNull('actor_type');
        foreach ($query->lazyById(200) as $row) {
            $path = $walker->walk($row->actor_type, $row->actor_id, $row->cached_actor_id);
            if ($path['has_parent']) {
                yield Finding::warning('ancestors.actor_only',
                    "Activity #{$row->getKey()} has an actor with a parent chain but no object, target or context. A self-acting container must record itself as the object too; actors never contribute ancestors.",
                    ['activity_id' => $row->getKey()],
                );
            }
        }
    }
}
