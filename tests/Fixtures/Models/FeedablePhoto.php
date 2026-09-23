<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/**
 * The shape one consumer uses for a vendor model: a subclass that exists so
 * `object_type` resolves to something Feedable, over the parent's own table.
 * The app deletes the row as a Photo, so the subclass's events never fire.
 *
 * @property int $id
 * @property string $file_name
 */
class FeedablePhoto extends Photo implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'photos';

    public function describeFeed(): void
    {
        $this->feedEntity()->label($this->file_name);
    }
}
