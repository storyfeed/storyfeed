<?php

namespace Storyfeed\Concerns;

use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\StoryfeedManager;

/**
 * Keeps a Feedable model's presence in the feed in sync with its lifecycle —
 * refreshes its snapshot on save, removes its activities on delete — and gives
 * the model a feed of its own.
 */
trait InteractsWithFeed
{
    public static function bootInteractsWithFeed(): void
    {
        static::saved(function ($model) {
            $model->updateFeedSnapshot();
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
     * Needs `feed_participants` populated. On an existing install that means
     * running `storyfeed:participants` once; `storyfeed:doctor` warns until it
     * has been.
     */
    public function storyfeed(): FeedBuilder
    {
        return app(StoryfeedManager::class)->feed()->involving($this);
    }

    public function updateFeedSnapshot(): void
    {
        (new SnapshotEntity)($this);
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
     * activities that were already soft-deleted.
     */
    public function forceDeleteFromFeed(): void
    {
        $this->newFeedActivityQuery()->withTrashed()->involving($this)->forceDelete();
    }

    protected function newFeedActivityQuery(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
