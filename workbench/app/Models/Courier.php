<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * The string-keyed consumer, in the workbench rather than in a test's head.
 *
 * A morph id is not always an integer. Everything else in this workbench has
 * an auto-incrementing `id`, which is the affinity under which `(int)` is the
 * identity function and a whole class of key-casting defects agrees with
 * itself (todo 875). Courier fills a feed role with a ULID so the ops path
 * has one model it cannot be wrong about quietly.
 *
 * @property string $id
 * @property string $name
 */
class Courier extends Model implements Feedable
{
    use HasUlids;
    use InteractsWithFeed;

    protected $guarded = [];

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(
            label: $this->name,
            data: ['id' => $this->id, 'name' => $this->name],
        );
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make('/couriers/'.$context->data('id'));
    }
}
