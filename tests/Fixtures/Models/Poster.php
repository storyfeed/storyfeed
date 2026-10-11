<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;

/**
 * A model built for display and never saved: no key, no row. Its media is
 * read from the live model rather than the snapshot, so it links only when
 * the model reaches its resolver (#138).
 *
 * @property string $title
 * @property string $slug
 * @property string $cover
 */
class Poster extends Model implements Feedable
{
    protected $guarded = [];

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(label: $this->title);
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        $model = $context->model(withCount: ['missing']);

        if (! $model instanceof self) {
            return null;
        }

        return FeedMedia::make(link: FeedLink::to("/posters/{$model->slug}"), image: $model->cover);
    }

    /**
     * Counted by the resolver above: an unsaved model has no row to count.
     *
     * @return HasMany<self, $this>
     */
    public function missing(): HasMany
    {
        return $this->hasMany(self::class, 'id');
    }
}
