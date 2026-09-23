<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Builder;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\SyncToken;

/**
 * A force-deleted composite parent releases its members back to inference —
 * the story is gone, but the events outlive it (the same doctrine as
 * parties: history outlives a retired integration).
 *
 * Soft-deleted parents release nothing: the cluster keeps rendering from
 * its members, and restoration puts the story back intact.
 *
 * THREE WAYS IN. One parent force-deleted through Eloquent (`__invoke`,
 * from the model event); a chunk of rows about to be bulk-deleted
 * (`parentsAmong`, from PurgeActivities and ForceDeleteFromFeed, which fire
 * no events); and the claims a parent already left behind (`dangling`).
 *
 * The last is a repair, not a path. Until 2026-09-23 ForceDeleteFromFeed
 * forgot a parent's own claim row and deleted it without releasing its
 * members, and until aa5b6b2 prune did the same. Nothing was hidden: the
 * composite node is built from its members' claim rows, so it went on
 * rendering, byte for byte, as though the parent were still there. What was
 * wrong is that the story outlived an erasure that should have dissolved
 * it, and the three erasure paths disagreed about what a gone parent means.
 * `storyfeed:curate --release` hands those members back, and the doctor's
 * `claims` check counts what is left.
 */
class ReleaseComposite
{
    public const CHUNK = 500;

    public function __invoke(Activity|ActivitySnapshot $activity): void
    {
        // Event listeners use the captured flag; direct model callers retain
        // the existing transient-flag/exists behavior.
        if (! ($activity instanceof ActivitySnapshot ? $activity->forceDeleted : $activity->isForceDeleting() || ! $activity->exists)) {
            return;
        }

        $isParent = $this->groupings()
            ->where('activity_id', $activity->id)
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->exists();

        if (! $isParent) {
            return;
        }

        $this->release((string) $activity->uid);
    }

    /**
     * Release the members of every composite whose parent is among these
     * ids, before a bulk delete takes the parent's claim row with it. The
     * claim rows are the only index from a parent to its members, so this
     * has to run first. Members in the same chunk are leaving too, so they
     * lose their claim and nothing else: curating them into a cluster they
     * are about to leave would stamp its survivors against a member that is
     * gone.
     *
     * @param  list<int|string>  $ids
     */
    public function parentsAmong(array $ids): void
    {
        $claims = $this->groupings()->where('bucket', 'composite')->whereIn('activity_id', $ids)->pluck('hash', 'activity_id');

        if ($claims->isEmpty()) {
            return;
        }

        foreach ($this->activities()->withTrashed()->whereKey($claims->keys()->all())->toBase()->get(['id', 'uid']) as $row) {
            // A parent's own claim is keyed by its uid; a member's is not.
            if ($claims[$row->id] === $row->uid) {
                $this->release((string) $row->uid, except: $ids);
            }
        }
    }

    /**
     * Release every member claimed by a composite whose parent no longer
     * exists, trashed included. Idempotent: a released claim is gone, so a
     * second run finds nothing, and a healthy feed has nothing to find.
     *
     * Moves `sync_token` when it released anything: a composite node in
     * settled history becomes whatever inference makes of its members, with
     * a new node id, which accumulated pages cannot reconcile.
     *
     * @return int members released
     */
    public function dangling(): int
    {
        $released = 0;

        // Each pass releases the hashes it read, so the next pass sees only
        // what is left, the same convergence ForceDeleteFromFeed relies on.
        while (($hashes = static::danglingClaims()->distinct()->limit(self::CHUNK)->pluck('hash')->all()) !== []) {
            foreach ($hashes as $hash) {
                $released += $this->release((string) $hash);
            }
        }

        if ($released > 0) {
            SyncToken::bump();
        }

        return $released;
    }

    /**
     * Composite claim rows whose parent is gone: no activity, trashed
     * included, carries the uid the claim is keyed by. A soft-deleted parent
     * still owns its members, and a restore puts the story back.
     *
     * @return Builder<Grouping>
     */
    public static function danglingClaims(): Builder
    {
        $grouping = config('storyfeed.models.grouping', Grouping::class);
        $model = config('storyfeed.models.activity', Activity::class);
        $groupings = (new $grouping)->getTable();
        $activities = (new $model)->getTable();

        return $grouping::query()
            ->where("{$groupings}.bucket", 'composite')
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from($activities)
                ->whereColumn("{$activities}.uid", "{$groupings}.hash"));
    }

    /**
     * Drop every claim under one composite and hand its live members back
     * to inference, all but those `$except` names.
     *
     * @param  list<int|string>  $except
     * @return int members released
     */
    protected function release(string $hash, array $except = []): int
    {
        return $this->groupings()->getConnection()->transaction(function () use ($hash, $except) {
            $memberIds = $this->activities()->withTrashed()
                ->whereIn('id', $this->groupings()->where('bucket', 'composite')->where('hash', $hash)->select('activity_id'))
                ->where('uid', '!=', $hash)
                ->pluck('id');

            // Claims released first, so WriteGroupings' claimed-guard passes.
            $this->groupings()->where('bucket', 'composite')->where('hash', $hash)->delete();

            $write = new WriteGroupings;
            $curate = new CurateCluster;

            foreach ($this->activities()->whereKey($memberIds)->whereKeyNot($except)->get() as $member) {
                $write($member);
                $curate($member);
            }

            return $memberIds->count();
        });
    }

    /** @return ActivityBuilder<Activity> */
    protected function activities(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }

    /** @return Builder<Grouping> */
    protected function groupings(): Builder
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query();
    }
}
