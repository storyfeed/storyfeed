<?php

namespace Storyfeed\ActivityStreams;

use Storyfeed\ActivityStreams\Concerns\IsVocabularyTerm;

/**
 * Activity Streams 2.0 object types, including the five so-called "actor"
 * types.
 *
 * They live in one enum deliberately: AS2 has no separate actor class —
 * Person IS an Object — and a feed entity maps to one type regardless of the
 * role it plays in a given activity (a user is a Person whether actor or
 * object). A separate ActorType enum would manufacture a distinction the
 * spec doesn't make, and hardcoded actor allowlists are a well-documented
 * source of federation interop bugs.
 *
 * @see https://www.w3.org/TR/activitystreams-vocabulary/#object-types
 */
enum ObjectType: string implements VocabularyTerm
{
    use IsVocabularyTerm;

    case Object = 'Object';

    // Core object types
    case Article = 'Article';
    case Audio = 'Audio';
    case Document = 'Document';
    case Event = 'Event';
    case Image = 'Image';
    case Note = 'Note';
    case Page = 'Page';
    case Place = 'Place';
    case Profile = 'Profile';
    case Relationship = 'Relationship';
    case Tombstone = 'Tombstone';
    case Video = 'Video';

    // Actor types
    case Application = 'Application';
    case Group = 'Group';
    case Organization = 'Organization';
    case Person = 'Person';
    case Service = 'Service';

    /**
     * Whether this is one of the five conventional actor types.
     *
     * ADVISORY ONLY. Never use this to validate or filter: AS2 and
     * ActivityPub both permit any object to act as an actor, and gating on
     * this set is precisely the interop bug other implementations hit.
     */
    public function isActor(): bool
    {
        return match ($this) {
            self::Application, self::Group, self::Organization, self::Person, self::Service => true,
            default => false,
        };
    }

    public function parent(): ?self
    {
        return match ($this) {
            self::Audio, self::Image, self::Page => self::Document,
            default => null,
        };
    }
}
