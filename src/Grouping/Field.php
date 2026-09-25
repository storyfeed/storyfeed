<?php

namespace Storyfeed\Grouping;

use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;

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
 * 64-bit ints, 16 bits used — ample headroom for future dimensions.
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

    case OriginType = 1 << 10;
    case OriginId = 1 << 11;
    case ResultType = 1 << 12;
    case ResultId = 1 << 13;
    case InstrumentType = 1 << 14;
    case InstrumentId = 1 << 15;

    public const CANONICAL_ORDER = [
        self::ActorType, self::ActorId,
        self::Verb,
        self::ObjectType, self::ObjectId,
        self::TargetType, self::TargetId,
        self::ContextType, self::ContextId,
        self::OriginType, self::OriginId,
        self::ResultType, self::ResultId,
        self::InstrumentType, self::InstrumentId,
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
        'ora' => self::OriginType,
        'orid' => self::OriginId,
        'ra' => self::ResultType,
        'rid' => self::ResultId,
        'ia' => self::InstrumentType,
        'iid' => self::InstrumentId,
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
        ':origin' => self::OriginType->value | self::OriginId->value,
        ':result' => self::ResultType->value | self::ResultId->value,
        ':instrument' => self::InstrumentType->value | self::InstrumentId->value,
        ':verb' => self::Verb->value,
    ];

    /**
     * THE DAY SEGMENT HOLDS THE VERB'S PERIOD, CUT IN THE APPLICATION'S ZONE,
     * at PUBLISH time. A day unless the verb declared otherwise (Period):
     * `2026-09-23`, or `2026-09-23T14`, `2026-W39`, `2026-09`.
     *
     * The value reads `app.timezone` — usually UTC — and a renderer's day
     * headings are cut in its own DISPLAY zone, at read time. The two can
     * disagree, and when they do the GROUP wins: a burst straddling midnight in
     * the reader's zone is one group under one heading, because the members
     * were bound together before any renderer had a zone to have an opinion in.
     *
     * A consumer proved it with seven link-opens either side of midnight in
     * Ontario — four on the 26th locally, three on the 27th, all seven the 27th
     * in UTC — which rendered as a single group under "Today" with half of it
     * belonging to yesterday. Nothing looks wrong: the rows are ordered, the
     * count is right, and the run simply reads as today's.
     *
     * The same holds for every period: a week's Monday and a month's first
     * are cut in the application's zone too.
     *
     * This is a PROPERTY, not a defect to route around. The grouping period is a
     * publish-time value written into a hash, and it cannot know a read-time
     * zone that may differ per reader; making it agree would mean taking the
     * day out of the key entirely, which is a different design with different
     * costs. An app that needs "yesterday" to mean the reader's yesterday
     * should set `app.timezone` to the zone its feed is read in, so both cuts
     * land in the same place.
     */
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
            self::OriginType => $activity->origin_type,
            self::OriginId => $activity->origin_id,
            self::ResultType => $activity->result_type,
            self::ResultId => $activity->result_id,
            self::InstrumentType => $activity->instrument_type,
            self::InstrumentId => $activity->instrument_id,
            self::Day => self::period($activity)->valueFor($activity->published_at ?? now()),
        };

        return $raw === null ? '' : (string) $raw;
    }

    /**
     * The period the activity's verb declared (`->groupedWeekly()` and the
     * rest), on the type → verb ladder; a day when nothing reaches it.
     */
    protected static function period(Activity $activity): Period
    {
        $type = $activity->object_type;

        return app(StoryfeedManager::class)->period(
            is_string($type) && $type !== '' ? $type : null,
            (string) $activity->verb,
        );
    }

    public function isFilledOn(Activity $activity): bool
    {
        return $this === self::Day || $this->valueFor($activity) !== '';
    }
}
