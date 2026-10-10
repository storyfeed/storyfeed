<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * A showcase project, as teylabs.com keeps them: no date column, ordered by
 * a manual `position`. Never saved here: composed feeds read models as
 * given, so the tests build them in memory.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $tagline
 * @property int $position
 */
class Project extends Model implements Feedable
{
    protected $guarded = [];

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(label: $this->name, data: ['slug' => $this->slug]);
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make("https://teylabs.com/projects/{$context->data('slug')}");
    }
}
