<?php

namespace Storyfeed\Concerns;

use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * Keeps a Feedable model's presence in the feed in sync with its lifecycle:
 * refreshes its snapshot on save, and removes its activities on delete.
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
