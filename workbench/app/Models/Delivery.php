<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasFeedShapeVersion;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $tracking_number
 * @property string $status
 * @property-read Customer|null $customer
 */
class Delivery extends Model implements Feedable, HasFeedShapeVersion
{
    use InteractsWithFeed;
    use SoftDeletes;

    /** Test spy: how often the package asked for live media. */
    public static int $feedMediaCalls = 0;

    /** Test hook: simulate a deployed change to toFeed()'s data shape. */
    public static bool $extendedFeedShape = false;

    /** Test hook: declared semantic version (HasFeedShapeVersion escape hatch). */
    public static int $feedShapeVersion = 1;

    /** Test hook: make the resolver ask for its live model (issue #4). */
    public static bool $hydrates = false;

    /** Test hook: relations passed as `with:` when hydrating. */
    public static array $hydratesWith = [];

    /** Test hook: read the customer relation off the hydrated model (the nested-access footgun). */
    public static bool $readsCustomer = false;

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
                ...(static::$extendedFeedShape ? ['carrier' => ['name' => 'ACME', 'code' => 'AC']] : []),
            ],
            component: 'Resource',
        );
    }

    public static function feedShapeVersion(): int
    {
        return static::$feedShapeVersion;
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        static::$feedMediaCalls++;

        if (static::$hydrates) {
            $model = $context->model(with: static::$hydratesWith);

            if (! $model instanceof self) {
                return null;
            }

            $attributes = ['data-status' => $model->status];

            if (static::$readsCustomer) {
                $attributes['data-customer'] = $model->customer?->name;
            }

            return FeedMedia::make("/deliveries/{$model->id}", attributes: $attributes);
        }

        // Deliberately strict about the raw array — a naive real-world
        // implementation looks like this, and the package must never call
        // it with empty data (see FrictionRegressionTest).
        $data = $context->data();

        return FeedMedia::make("/deliveries/{$data['id']}", attributes: ['data-status' => $data['status'] ?? null]);
    }
}
