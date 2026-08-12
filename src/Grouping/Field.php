<?php

namespace Storyfeed\Grouping;

use Storyfeed\Models\Activity;

/**
 * The fields an axis recipe can reference, as bit flags. The recipe DSL
 * (`'aa:aid:v:oa!:oid!:d'`) is the authoring surface; it compiles to two
 * masks (fields, required) over these bits, and all derivations become
 * mask algebra — which fields a key uses, whether an axis applies to an
 * activity, and which headline tokens the axis pins.
 *
 * Key assembly walks CANONICAL_ORDER filtered by the mask. The canonical
 * order is contract-critical: every built-in recipe already fits it, and
 * deployed feed_groupings rows contain keys assembled in exactly this
 * order (see AxisHashStabilityTest).
 *
 * 64-bit ints, 10 bits used — ample headroom for future dimensions.
 */
enum Field: int
{
    case ActorType = 1 << 0;      // aa
    case ActorId = 1 << 1;        // aid
    case Verb = 1 << 2;           // v
    case ObjectType = 1 << 3;     // oa
    case ObjectId = 1 << 4;       // oid
    case TargetType = 1 << 5;     // ta
    case TargetId = 1 << 6;       // tid
    case ContextType = 1 << 7;    // ca
    case ContextId = 1 << 8;      // cid
    case Day = 1 << 9;            // d

    public const CANONICAL_ORDER = [
        self::ActorType, self::ActorId,
        self::Verb,
        self::ObjectType, self::ObjectId,
        self::TargetType, self::TargetId,
        self::ContextType, self::ContextId,
        self::Day,
    ];

    /** The concrete token map — the DSL's whole vocabulary. */
    public const TOKENS = [
        'aa' => self::ActorType,
        'aid' => self::ActorId,
        'v' => self::Verb,
        'oa' => self::ObjectType,
        'oid' => self::ObjectId,
        'ta' => self::TargetType,
        'tid' => self::TargetId,
        'ca' => self::ContextType,
        'cid' => self::ContextId,
        'd' => self::Day,
    ];

    /**
     * The role each identity PAIR pins: when both bits of a pair are in an
     * axis's field mask, that role is homogeneous across the axis's groups
     * and its headline token is safe.
     *
     * @var array<string, int>
     */
    public const PINNABLE = [
        ':actor' => self::ActorType->value | self::ActorId->value,
        ':object' => self::ObjectType->value | self::ObjectId->value,
        ':target' => self::TargetType->value | self::TargetId->value,
        ':context' => self::ContextType->value | self::ContextId->value,
    ];

    public function valueFor(Activity $activity): string
    {
        $raw = match ($this) {
            self::ActorType => $activity->actor_type,
            self::ActorId => $activity->actor_id,
            self::Verb => $activity->verb,
            self::ObjectType => $activity->object_type,
            self::ObjectId => $activity->object_id,
            self::TargetType => $activity->target_type,
            self::TargetId => $activity->target_id,
            self::ContextType => $activity->context_type,
            self::ContextId => $activity->context_id,
            self::Day => ($activity->published_at ?? now())->toDateString(),
        };

        return $raw === null ? '' : (string) $raw;
    }

    public function isFilledOn(Activity $activity): bool
    {
        return $this === self::Day || $this->valueFor($activity) !== '';
    }
}
