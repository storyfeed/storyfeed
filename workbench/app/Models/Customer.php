<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * @property int $id
 * @property string $name
 */
class Customer extends Model implements Feedable
{
    /** The last context handed to feedMedia(), for tests to inspect. */
    public static ?FeedContext $lastContext = null;

    /** Test hook: make the resolver ask for its live model (issue #4). */
    public static bool $hydrates = false;

    /** Test hook: `withTrashed:` passed through when hydrating. */
    public static bool $hydratesTrashed = false;

    /** Test spy: every model (or null) model() handed back, in call order. */
    public static array $hydrated = [];

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

    /**
     * The shape issue #3 exists for: one snapshot, a different URL per
     * surface, chosen by the feed's declared name and never by the request.
     * An ad-hoc feed and the AS2 serializer both report no feed and take the
     * default arm.
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        static::$lastContext = $context;

        if (static::$hydrates) {
            // The hydrating shape: link from the live row, label from the live
            // row too — closing the fresh-link/stale-label gap the accessor
            // opens — and no link at all when the row is gone or hidden.
            $model = $context->model(withTrashed: static::$hydratesTrashed);
            static::$hydrated[] = $model;

            // `instanceof self` rather than a null check: a static resolver is
            // always asking for its own class, and the test narrows the type
            // for static analysis where `!== null` leaves a bare Model.
            if (! $model instanceof self) {
                return null;
            }

            return FeedMedia::make("/customers/{$model->id}", label: $model->name);
        }

        return match ($context->feed()) {
            'kitchen' => FeedMedia::make('/kitchen/customers/'.$context->data('id')),
            default => FeedMedia::make('/customers/'.$context->data('id')),
        };
    }
}
