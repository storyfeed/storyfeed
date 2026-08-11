<?php

namespace Workbench\App\Enums;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Concerns\AsFeedVerb;
use Storyfeed\Contracts\FeedVerb;

/**
 * The workbench app's verb vocabulary — the recommended pattern: one enum
 * as the single source of truth, registered with Storyfeed::verbs(self::class).
 */
enum ActivityVerb: string implements FeedVerb
{
    use AsFeedVerb;

    case Confirm = 'confirm';
    case Upload = 'upload';
    case Comment = 'comment';
    case Create = 'create';

    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Confirm => ActivityType::Update,
            self::Upload => ActivityType::Add,
            self::Comment, self::Create => ActivityType::Create,
        };
    }
}
