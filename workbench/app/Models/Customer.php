<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasFeedMedia;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;

/**
 * @property int $id
 * @property string $name
 */
class Customer extends Model implements Feedable, HasFeedMedia
{
    /** Counts calls to the OLD contract, which the read path must skip once feedMedia() exists. */
    public static int $feedLinkCalls = 0;

    /** The last context handed to feedMedia(), for tests to inspect. */
    public static ?FeedContext $lastContext = null;

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
     * Kept alongside feedMedia() to prove the dispatch prefers the new
     * contract when both exist.
     */
    public static function toFeedLink(array $data): ?FeedLink
    {
        static::$feedLinkCalls++;

        return FeedLink::make("/customers/{$data['id']}");
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

        return match ($context->feed()) {
            'kitchen' => FeedMedia::make('/kitchen/customers/'.$context->data('id')),
            default => FeedMedia::make('/customers/'.$context->data('id')),
        };
    }
}
