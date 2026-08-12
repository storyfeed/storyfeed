<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $tracking_number
 * @property string $status
 */
class Delivery extends Model implements Feedable
{
    use InteractsWithFeed;
    use SoftDeletes;

    /** Test spy: how often the package asked for a live link. */
    public static int $feedLinkCalls = 0;

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(
            label: "Delivery #{$this->tracking_number}",
            data: [
                'id' => $this->id,
                'tracking_number' => $this->tracking_number,
                'status' => $this->status,
            ],
            component: 'Resource',
        );
    }

    public static function toFeedLink(array $data): ?FeedLink
    {
        static::$feedLinkCalls++;

        // Deliberately strict about $data['id'] — a naive real-world
        // implementation looks like this, and the package must never call
        // it with empty data (see FrictionRegressionTest).
        return FeedLink::make("/deliveries/{$data['id']}", attributes: ['data-status' => $data['status'] ?? null]);
    }
}
