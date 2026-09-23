<?php

namespace Storyfeed\Tests\Fixtures\Guessed;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/**
 * A Feedable that describes itself, so its label isn't guessed.
 */
class Menu extends Model implements Feedable
{
    use InteractsWithFeed;

    public function describeFeed(): void
    {
        $this->feedEntity()->label('The menu');
    }
}
