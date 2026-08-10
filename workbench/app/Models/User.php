<?php

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
class User extends Authenticatable implements Feedable
{
    use InteractsWithFeed;

    protected $guarded = [];

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(
            label: $this->name,
            data: ['id' => $this->id, 'name' => $this->name],
        );
    }

    public static function toFeedLink(array $data): ?FeedLink
    {
        return FeedLink::make("/users/{$data['id']}");
    }
}
