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
     * What each token needs in an axis's field mask before it is homogeneous
     * across that axis's groups — and therefore safe in an aggregate headline.
     *
     * A ROLE needs both bits of its identity PAIR: an alias alone pins "some
     * delivery", not "this delivery".
     *
     * `:verb` needs one bit, and it was missing from this list until
     * 2026-08-26 — an omission rather than a decision. The verb field is in
     * four of the five inferred axis keys, so every member of such a group
     * shares a verb by exactly the construction that makes `:actor` safe on
     * `repeat`. While it was absent, `pinnedTokens()` under-claimed: an
     * aggregate template naming the verb was refused for a group where naming
     * it was simply true, and a renderer meeting an ungrammared group could
     * not ask whether the verb was sayable — so it said "31 activities" where
     * "31 clause.added activities" was available and honest.
     *
     * @var array<string, int>
     */
    public const PINNABLE = [
        ':actor' => self::ActorType->value | self::ActorId->value,
        ':object' => self::ObjectType->value | self::ObjectId->value,
        ':target' => self::TargetType->value | self::TargetId->value,
        ':context' => self::ContextType->value | self::ContextId->value,
        ':verb' => self::Verb->value,
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
