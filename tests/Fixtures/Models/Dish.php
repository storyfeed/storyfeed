<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Body\Excerpt;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/**
 * A model described the way the guide teaches: describeFeed() for the stored
 * half, feedMediaUsing() in booted() for the read-time half, and no
 * FeedEntity, FeedContext or FeedMedia import.
 *
 * Also the PHPStan fixture for that claim: phpstan-fixture.neon.dist
 * analyses this file at level 7, so a change that made either half need an
 * interface type (or an annotation) to pass fails CI.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $number
 * @property string|null $course
 * @property string|null $notes
 */
class Dish extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $guarded = [];

    public function describeFeed(): void
    {
        if ($this->number === null) {
            return;
        }

        $this->feedEntity()
            ->label("Dish #{$this->number}")
            ->data(['course' => $this->course])
            ->body(Excerpt::make()->text($this->notes));
    }

    protected static function booted(): void
    {
        static::feedMediaUsing(fn ($context, $media) => $media
            ->url("/dishes/{$context->routeKey()}")
            ->icon('/icons/dish.png'));
    }
}
