<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * Soft-delete every activity involving a model. What a Feedable's `deleted`
 * event does, whether the model uses InteractsWithFeed or was registered
 * with `Storyfeed::feedable()`.
 *
 * Chunked because the live scope excludes what the last pass soft-deleted,
 * so the loop converges without a running exclusion list.
 *
 * It used to record removal evidence per chunk as well — the bulk
 * `delete()` fires no model events, so nothing downstream heard the rows
 * go. That evidence is gone: it answered a question the package never
 * asked, for a healer whose contract says it "never infers missing
 * stories", and an app that needs it can keep its own record.
 */
class DeleteFromFeed
{
    public function __invoke(Model $model): void
    {
        while (true) {
            $ids = self::query()
                ->involving($model)
                ->limit(500)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            self::query()->whereKey($ids)->delete();
        }
    }

    /** @return ActivityBuilder<Activity> */
    public static function query(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
