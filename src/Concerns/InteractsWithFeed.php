<?php

namespace Storyfeed\Concerns;

use Storyfeed\Actions\ForgetActivities;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\StoryfeedManager;

/**
 * Keeps a Feedable model's presence in the feed in sync with its lifecycle —
 * refreshes its snapshot on save, removes its activities on delete — and gives
 * the model a feed of its own.
 *
 * It also satisfies half of the Feedable contract, so that
 *
 *     class Delivery extends Model implements Feedable
 *     {
 *         use InteractsWithFeed;
 *
 * compiles on first save with only toFeed() left to write. Which half is
 * the point, and it is not the half you would guess from "make it compile":
 * the trait defaults feedMedia() and deliberately NOT toFeed(). See
 * feedMedia() below for why.
 */
trait InteractsWithFeed
{
    public static function bootInteractsWithFeed(): void
    {
        // The lifecycle half of the recording switch. feed_snapshots is the
        // highest-churn feed table because this hook fires for EVERY save of
        // every Feedable model, activity or not — one consumer's parallel
        // suite hit Postgres autovacuum deadlocks on exactly that table. The
        // explicit call below is deliberately not gated: a method does what
        // its name says; only the automatic write is muted.
        static::saved(function ($model) {
            if (app(StoryfeedManager::class)->isRecording()) {
                $model->updateFeedSnapshot();
            }
        });

        static::deleted(function ($model) {
            $model->deleteFromFeed();
        });

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function ($model) {
                $model->forceDeleteFromFeed();
            });
        }
    }

    /**
     * This model's feed: every activity it took part in, in any role.
     *
     *   $project->storyfeed()->summary()->get();
     *
     * Exactly equivalent to the facade form, with the argument already filled
     * in — the same builder, so every method still applies:
     *
     *   Storyfeed::feed()->involving($project)->summary()->get();
     *
     * Not to be confused with the `storyfeed()` HELPER, which returns the
     * manager, or a pending activity when given a verb. Both are reachable from
     * inside a model: `storyfeed()` is the function, `$this->storyfeed()` this.
     *
     * A named feed narrows it to one audience's verbs, declared once in a
     * service provider with `Storyfeed::feeds([...])` — see docs/feeds.md:
     *
     *   $order->storyfeed('customer')->get();
     *
     * The feed's constraints apply BEFORE involving(), so the two compose: this
     * order's timeline, as a customer may see it.
     *
     * A Feed CLASS that takes its subject as a constructor argument cannot be
     * entered this way — it is built through its constructor instead, and the
     * role it binds is its own rather than involving():
     *
     *   CustomerFeed::make($order)->get();
     *
     * Needs `feed_participants` populated. On an existing install that means
     * running `storyfeed:participants` once; `storyfeed:doctor` warns until it
     * has been.
     */
    public function storyfeed(?string $preset = null): FeedBuilder
    {
        return app(StoryfeedManager::class)->feed($preset)->involving($this);
    }

    public function updateFeedSnapshot(): void
    {
        (new SnapshotEntity)($this);
    }

    /**
     * Not independently linkable, until you say otherwise.
     *
     * A MISSING LINK IS A STATE; A MISSING LABEL IS A DEFECT. Returning null
     * here is honest and common — one consumer returns it from all four of
     * its models on purpose, because the same snapshot renders on three
     * surfaces and the right URL depends on who is reading. The feed renders
     * the entity at full weight, just not clickable. So the trait answers
     * for this method and the model overrides it when it has somewhere to
     * point.
     *
     * toFeed() gets no such default. Whatever it guessed — the class name,
     * the primary key — would write a DEGRADED snapshot, and the feed would
     * quietly read as a placeholder instead of failing. A label the author
     * never wrote is not a state the reader can tell from a bug, so the
     * trait leaves toFeed() to redline until it is written. The trait
     * satisfies only the method where "nothing" is a real answer.
     *
     * A doctor check reporting "this model appears in feeds and never
     * resolves a link" as Info is the follow-on: making the null visible
     * rather than forbidding it.
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return null;
    }

    /**
     * Soft-delete every activity involving this model.
     */
    public function deleteFromFeed(): void
    {
        $this->newFeedActivityQuery()->involving($this)->delete();
    }

    /**
     * Permanently delete every activity involving this model, including
     * activities that were already soft-deleted — and everything that points
     * at them.
     *
     * A bulk `forceDelete()` fires no model events, so nothing downstream
     * hears about the rows going. Until 2026-09-05 this method was that one
     * query, and it left `feed_groupings` and `feed_participants` rows behind
     * pointing at primary keys that no longer existed. It was the one
     * hard-delete path with no opt-in in front of it: `replace()` defaults to
     * soft, the trickle prunes only when asked, but this fires for every
     * `Feedable` that is force-deleted. So the ids are collected first and
     * `Actions\ForgetActivities` clears their rows before the delete, the
     * same way `PruneActivities` does it.
     *
     * Still a bulk operation, deliberately. Per-model deletes would get the
     * events back at the cost of a query per activity on exactly the path
     * that exists to be fast, and curation has nothing to re-decide for a
     * cluster whose members are all leaving at once.
     *
     * Chunked because `involving()` is an index over the participants table:
     * each pass forgets the rows it deletes, so the next pass sees only what
     * is left and the loop converges without a running exclusion list.
     */
    public function forceDeleteFromFeed(): void
    {
        $forget = new ForgetActivities;

        while (true) {
            $ids = $this->newFeedActivityQuery()
                ->withTrashed()
                ->involving($this)
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $forget(...$ids);

            $this->newFeedActivityQuery()->withTrashed()->whereKey($ids)->forceDelete();
        }
    }

    protected function newFeedActivityQuery(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
