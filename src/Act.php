<?php

namespace Storyfeed;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Concerns\AsFeedVerb;
use Storyfeed\Contracts\FeedVerb;

/**
 * The common verbs, so an application does not have to invent them.
 *
 *   Act::Send->actor($user)->object($proposal)->to($client)->publish();
 *
 * WHY THIS EXISTS. Activity Streams gives 28 activity types, and they are
 * deliberately abstract: sending an invoice is `Offer`, a spam complaint is
 * `Flag`, voiding a signature is `Undo`. An author who wants the stored verb
 * to read the way a person speaks has to invent one — and inventing is where
 * verb sprawl starts. Three applications surveyed for this enum had grown 103
 * bespoke verbs between them, every one of which mapped to just 16 AS2 types.
 *
 * So this is the vocabulary, already mapped. Take a word from it and the
 * serialization is correct without thinking about it.
 *
 * WHY IT IS LARGE, WHEN THE RESEARCH SAYS APPS SHOULD HAVE FEW VERBS. A
 * dictionary is not a sentence. An application picking eight of these is
 * disciplined; the same application inventing eight is not, because invented
 * verbs are unmapped, undocumented and unshared. The count here is not a
 * budget for any one app to spend.
 *
 * WHY MOST OF THEM SIT UNDER Update AND Create CARRIES FEW. Those two types
 * took 49 of the 103 verbs surveyed, but for opposite reasons. `Create` is
 * coarse in name only — the object says what was made, so `create` plus a
 * Comment and `create` plus a Project need no separate words. `Update` is
 * genuinely coarse: pausing, renaming, cancelling and completing are
 * different facts about the world that AS2 spells identically. The plain
 * verbs earn their place exactly where the AS2 type cannot tell two acts
 * apart.
 *
 * TENSE IS PRESENT, ALWAYS. `Send`, never `Sent`. It matches the AS2 types
 * this maps onto (`Create`, not `Created`) and `docs/verbs.md`, so there is
 * one convention rather than two. A vocabulary that mixed tenses would give
 * an app two strings for one fact and no error when it used both.
 *
 * SYNONYMS ARE NOT CASES. `publish`, `compose` and `write` all mean Create
 * with a different object; adding them would ship the sprawl this exists to
 * prevent. Where an app's own word is missing, the object role is usually
 * where the distinction belongs.
 *
 * ADDING A CASE IS PERMANENT. Consumers store the string, so a case removed
 * later does not remove the rows. Two tests before proposing one: it is not a
 * noun in disguise (see the verb guide), and it is not a stylistic variant of
 * a case that already exists.
 *
 * Verbs remain free-form strings in storage. This is an authoring
 * convenience, never a closed set — an app is free to ignore it entirely.
 */
enum Act: string implements FeedVerb
{
    use AsFeedVerb;

    // ── Create ───────────────────────────────────────────────────────────

    case Create = 'create';
    case Upload = 'upload';
    case Draft = 'draft';
    case Reply = 'reply';

    // ── Update ───────────────────────────────────────────────────────────
    // The coarse one. Each of these is a different fact that AS2 spells the
    // same way, which is the whole reason this enum is worth having.

    case Update = 'update';
    case Rename = 'rename';
    case Amend = 'amend';
    case Correct = 'correct';
    case Supersede = 'supersede';
    case Complete = 'complete';
    case Confirm = 'confirm';
    case Cancel = 'cancel';
    case Begin = 'begin';
    case End = 'end';
    case Pause = 'pause';
    case Resume = 'resume';
    case Extend = 'extend';
    case Shorten = 'shorten';
    case Enable = 'enable';
    case Disable = 'disable';
    case Settle = 'settle';

    // ── Delete ───────────────────────────────────────────────────────────

    case Delete = 'delete';
    case Discard = 'discard';

    // ── Undo ─────────────────────────────────────────────────────────────
    // Undo's object is an ACTIVITY, not a thing: an earlier act is called
    // off. That is why restoring lives here and not under Create.

    case Undo = 'undo';
    case Restore = 'restore';
    case Reinstate = 'reinstate';
    case Revert = 'revert';
    case Void = 'void';
    case Withdraw = 'withdraw';

    // ── Add ──────────────────────────────────────────────────────────────
    // The object already existed; it is now in a collection.

    case Add = 'add';
    case Attach = 'attach';
    case Apply = 'apply';
    case Record = 'record';

    // ── Remove ───────────────────────────────────────────────────────────
    // The object leaves a collection and continues to exist. Archiving is
    // Remove, not Delete — you can still link to the thing afterwards.

    case Remove = 'remove';
    case Retire = 'retire';
    case Archive = 'archive';
    case Release = 'release';
    case Detach = 'detach';

    // ── Membership ───────────────────────────────────────────────────────

    case Join = 'join';
    case Pair = 'pair';
    case Leave = 'leave';

    // ── Offer and invite ─────────────────────────────────────────────────
    // Offer is directed and expects an answer. Invite is an Offer whose
    // object is an invitation to participate.

    case Offer = 'offer';
    case Send = 'send';
    // Delivery completed. The one act in the surveyed corpus with no clean
    // AS2 home: `Arrive` is intransitive and the thing arriving is the
    // object, not the actor. Mapped to Create, matching the corpus decision
    // that a delivery outcome is a delivery record coming into existence.
    case Deliver = 'deliver';
    case Propose = 'propose';
    case Request = 'request';
    case Invite = 'invite';

    // ── Accept and reject ────────────────────────────────────────────────
    // These answer a prior Offer or Invite. An unprompted signal is Like.

    case Accept = 'accept';
    case Approve = 'approve';
    case Agree = 'agree';
    // Signing is accepting, and worth its own word: no reader of a feed
    // thinks "accepted the agreement" when they mean a signature.
    case Sign = 'sign';
    case Reject = 'reject';
    case Decline = 'decline';

    // The qualified pair. camelCase is the odd spelling in an otherwise
    // one-lowercase-word vocabulary, and it follows AS2, which already forces
    // `tentativeAccept` into any app storing the spec's own spelling.
    case TentativelyAccept = 'tentativelyAccept';
    case TentativelyReject = 'tentativelyReject';

    // ── Attention ────────────────────────────────────────────────────────
    // View for an impression, Read for deliberate consumption. Undecided
    // means View.

    case View = 'view';
    case Open = 'open';
    case Read = 'read';
    case Download = 'download';
    case Listen = 'listen';

    // ── Signal ───────────────────────────────────────────────────────────

    case Announce = 'announce';
    case Remind = 'remind';
    case Flag = 'flag';
    case Ask = 'ask';
    case Like = 'like';
    case Dislike = 'dislike';
    case Follow = 'follow';
    case Ignore = 'ignore';
    case Block = 'block';

    // ── Movement ─────────────────────────────────────────────────────────
    // Arrive and Travel are intransitive in AS2: they take no object. A
    // message reaching a mailbox is not Arrive — the thing arriving is the
    // object, so that is a delivery record being created.

    case Arrive = 'arrive';
    case Travel = 'travel';
    case Move = 'move';

    /**
     * The registration map for a chosen subset of the vocabulary.
     *
     *   Storyfeed::verbs(Act::only(Act::Add, Act::Update, Act::Retire));
     *
     * WHY A SUBSET IS THE NORMAL CASE. `Storyfeed::verbs(Act::class)` would
     * register all of them, and the doctor's grammar coverage would then
     * report every verb the app does not use as unauthored — dozens of
     * findings nobody can act on. An app declares the words it actually says.
     *
     * @return array<string, ActivityType>
     */
    public static function only(self ...$verbs): array
    {
        $map = [];

        foreach ($verbs as $verb) {
            $map[$verb->value] = $verb->activityType();
        }

        return $map;
    }

    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Create, self::Upload, self::Draft, self::Reply,
            self::Deliver => ActivityType::Create,

            self::Update, self::Rename, self::Amend, self::Correct,
            self::Supersede, self::Complete, self::Confirm, self::Cancel,
            self::Begin, self::End, self::Pause, self::Resume,
            self::Extend, self::Shorten, self::Enable, self::Disable,
            self::Settle => ActivityType::Update,

            self::Delete, self::Discard => ActivityType::Delete,

            self::Undo, self::Restore, self::Reinstate, self::Revert,
            self::Void, self::Withdraw => ActivityType::Undo,

            self::Add, self::Attach, self::Apply, self::Record => ActivityType::Add,

            self::Remove, self::Retire, self::Archive,
            self::Release, self::Detach => ActivityType::Remove,

            self::Join, self::Pair => ActivityType::Join,
            self::Leave => ActivityType::Leave,

            self::Offer, self::Send, self::Propose, self::Request => ActivityType::Offer,
            self::Invite => ActivityType::Invite,

            self::Accept, self::Approve, self::Agree, self::Sign => ActivityType::Accept,
            self::Reject, self::Decline => ActivityType::Reject,
            self::TentativelyAccept => ActivityType::TentativeAccept,
            self::TentativelyReject => ActivityType::TentativeReject,

            self::View, self::Open => ActivityType::View,
            self::Read, self::Download => ActivityType::Read,
            self::Listen => ActivityType::Listen,

            self::Announce, self::Remind => ActivityType::Announce,
            self::Flag => ActivityType::Flag,
            self::Ask => ActivityType::Question,
            self::Like => ActivityType::Like,
            self::Dislike => ActivityType::Dislike,
            self::Follow => ActivityType::Follow,
            self::Ignore => ActivityType::Ignore,
            self::Block => ActivityType::Block,

            self::Arrive => ActivityType::Arrive,
            self::Travel => ActivityType::Travel,
            self::Move => ActivityType::Move,
        };
    }
}
