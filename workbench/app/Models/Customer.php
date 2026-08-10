<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;

/**
 * @property int $id
 * @property string $name
 */
class Customer extends Model implements Feedable
{
    use InteractsWithFeed;
    use SoftDeletes;

    protected $guarded = [];

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(
            label: $this->name,
            data: ['id' => $this->id, 'name' => $this->name],
        );
    }

    public static function toFeedLink(array $data): ?FeedLink
    {
        return FeedLink::make("/customers/{$data['id']}");
    }
}
