<?php

namespace Storyfeed\ActivityStreams;

use Storyfeed\ActivityStreams\Concerns\IsVocabularyTerm;
use Storyfeed\Contracts\FeedVerb;

/**
 * The 28 Activity Streams 2.0 activity types.
 *
 * `Activity` and `IntransitiveActivity` are deliberately NOT cases here —
 * they are core types (see CoreType) and are only ever reached as fallbacks.
 * Keeping the abstract bases out prevents them being registered as if they
 * were real mappings.
 *
 * Implements FeedVerb so the spec vocabulary can be used directly as a verb
 * without an app declaring its own enum. The stored verb is lcfirst(value),
 * which reproduces every built-in verb key exactly (TentativeAccept →
 * 'tentativeAccept').
 *
 * @see https://www.w3.org/TR/activitystreams-vocabulary/#activity-types
 */
enum ActivityType: string implements FeedVerb, VocabularyTerm
{
    use IsVocabularyTerm;

    case Accept = 'Accept';
    case TentativeAccept = 'TentativeAccept';
    case Add = 'Add';
    case Arrive = 'Arrive';
    case Create = 'Create';
    case Delete = 'Delete';
    case Follow = 'Follow';
    case Ignore = 'Ignore';
    case Join = 'Join';
    case Leave = 'Leave';
    case Like = 'Like';
    case Offer = 'Offer';
    case Invite = 'Invite';
    case Reject = 'Reject';
    case TentativeReject = 'TentativeReject';
    case Remove = 'Remove';
    case Undo = 'Undo';
    case Update = 'Update';
    case View = 'View';
    case Listen = 'Listen';
    case Read = 'Read';
    case Move = 'Move';
    case Travel = 'Travel';
    case Announce = 'Announce';
    case Block = 'Block';
    case Flag = 'Flag';
    case Dislike = 'Dislike';
    case Question = 'Question';

    /**
     * Intransitive activities take no `object`.
     */
    public function isIntransitive(): bool
    {
        return match ($this) {
            self::Arrive, self::Travel, self::Question => true,
            default => false,
        };
    }

    public function coreType(): CoreType
    {
        return $this->isIntransitive()
            ? CoreType::IntransitiveActivity
            : CoreType::Activity;
    }

    /**
     * The four subtype relationships in the vocabulary.
     */
    public function parent(): ?self
    {
        return match ($this) {
            self::TentativeAccept => self::Accept,
            self::TentativeReject => self::Reject,
            self::Invite => self::Offer,
            self::Block => self::Ignore,
            default => null,
        };
    }

    /**
     * Whether this type is, or descends from, the given type.
     */
    public function isA(self $type): bool
    {
        return $this === $type || $this->parent()?->isA($type) === true;
    }

    public function verb(): string
    {
        return lcfirst($this->value);
    }

    public function activityType(): self
    {
        return $this;
    }
}
