<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;

/**
 * Auto-bundling: when a batch closes, homogeneous runs of COLLECTABLE
 * objects inside it become composite stories — "Tomás uploaded 6 files to
 * Spring Campaign" minted from six atomically-recorded uploads. The
 * developer never corrals activities; designation (Contracts\Collectable /
 * Storyfeed::collectables()) plus the batch quiet-window do it.
 *
 * A run = same (verb, object type, target, context) within one actor's
 * batch, with DISTINCT objects >= grouping.composite.min_objects. Rules
 * that keep this honest:
 *  - same-object repetition never bundles — that is the object axis's
 *    story ("made 5 revisions to X"), not a collection
 *  - undesignated types never bundle — declared intent only
 *  - mixed-verb residue stays unbundled (session batches remain digest
 *    material, never feed artifacts)
 *  - a single collectable act mints nothing: the atomic IS the collapsed
 *    collection-of-one
 *
 * Claiming members removes them from inference (their axis rows are
 * deleted; affected clusters re-decided), which is the non-monotone event
 * class deletions already are. Idempotent: claimed members are skipped.
 */
class BundleComposites
{
    /**
     * @return int composites minted. The auto on/off decision lives with
     *             the AUTOMATIC callers (batch close paths) — invoking this
     *             action directly (storyfeed:bundle) is explicit intent.
     */
    public function __invoke(Batch $batch): int
    {
        $manager = app(StoryfeedManager::class);

        $members = $batch->activities()->get();

        if ($members->isEmpty()) {
            return 0;
        }

        $claimed = $this->groupings()
            ->whereIn('activity_id', $members->modelKeys())
            ->where('bucket', 'composite')
            ->pluck('activity_id')
            ->all();

        $candidates = $members
            ->reject(fn (Activity $a) => in_array($a->getKey(), $claimed))
            ->filter(fn (Activity $a) => $a->object_id !== null
                && $manager->isCollectable($a->object_type));

        // Day-partitioned like every axis: a LIVE batch rarely spans
        // midnight, but a seeded/backfilled batch holds an actor's whole
        // history — without the day component, a week of uploads would
        // merge into one giant composite.
        $runs = $candidates->groupBy(fn (Activity $a) => implode("\x1f", [
            $a->verb,
            $a->object_type,
            (string) $a->target_type, (string) $a->target_id,
            (string) $a->context_type, (string) $a->context_id,
            $a->published_at?->toDateString() ?? '',
        ]));

        $min = (int) config('storyfeed.grouping.composite.min_objects', 2);
        $minted = 0;

        foreach ($runs as $run) {
            $distinctObjects = $run->unique(fn (Activity $a) => $a->object_type.':'.$a->object_id);

            if ($distinctObjects->count() < $min) {
                continue; // singles collapse for free; same-object runs belong to the object axis
            }

            $this->mint($run->sortBy('published_at')->values());
            $minted++;
        }

        return $minted;
    }

    /**
     * @param  Collection<int, Activity>  $run
     */
    protected function mint($run): void
    {
        $head = $run->last(); // newest — the story sits where the burst ended

        $affected = [];

        DB::transaction(function () use ($run, $head, &$affected) {
            $model = config('storyfeed.models.activity', Activity::class);

            /** @var Activity $parent */
            $parent = new $model;

            $parent->forceFill([
                'uid' => (string) Str::ulid(),
                'verb' => $head->verb,
                'actor_type' => $head->actor_type, 'actor_id' => $head->actor_id,
                'target_type' => $head->target_type, 'target_id' => $head->target_id,
                'context_type' => $head->context_type, 'context_id' => $head->context_id,
                'cached_actor_id' => $head->cached_actor_id,
                'cached_target_id' => $head->cached_target_id,
                'cached_context_id' => $head->cached_context_id,
                'published_at' => $head->published_at,
            ])->save();

            $this->groupings()->create([
                'activity_id' => $parent->getKey(),
                'bucket' => 'composite',
                'hash' => $parent->uid,
                'winner' => null,
            ]);

            $rowBacked = app(StoryfeedManager::class)->rowBackedBuckets();

            foreach ($run as $member) {
                // Claim: axis rows out (recording the clusters they leave),
                // composite row in. Batch rows stay — the window record is
                // history, not grouping.
                $released = $this->groupings()
                    ->where('activity_id', $member->getKey())
                    ->whereNotIn('bucket', $rowBacked)
                    ->get(['bucket', 'hash']);

                foreach ($released as $row) {
                    $affected[$row->bucket."\x1f".$row->hash] = [$row->bucket, $row->hash];
                }

                $this->groupings()
                    ->where('activity_id', $member->getKey())
                    ->whereNotIn('bucket', $rowBacked)
                    ->delete();

                $this->groupings()->create([
                    'activity_id' => $member->getKey(),
                    'bucket' => 'composite',
                    'hash' => $parent->uid,
                    'winner' => true,
                ]);
            }
        });

        // Members leaving clusters is non-monotone (same class as deletes):
        // an actors cluster can fall below threshold. Re-decide survivors.
        $curate = new CurateCluster;

        foreach ($affected as [$axis, $hash]) {
            $curate->repair($axis, $hash);
        }
    }

    protected function groupings()
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query();
    }
}
