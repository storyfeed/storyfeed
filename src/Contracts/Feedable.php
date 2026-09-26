<?php

namespace Storyfeed\Contracts;

use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * Implement on any model that can appear in the feed as an actor, object,
 * target, or context. One contract, two halves of one seam:
 *
 *  - toFeed()     : the cacheable snapshot (label + data), written to the
 *                   snapshots table when an activity is published and
 *                   refreshed whenever the model is saved.
 *  - feedMedia()  : STATIC. Resolves what the snapshot cannot cache — a url,
 *                   a label override, link attributes, a modal hint, the
 *                   image slots — from that snapshot at read time, so
 *                   labels stay fast and links never go stale.
 *
 * The snapshot holds what an entity IS; feedMedia() resolves what EXPIRES.
 * Read-time and static on purpose: signed URLs cannot be cached by
 * construction, absolute URLs vary by origin between a request and a queue
 * worker, and links built through a renderer's own registry resolve only
 * while that renderer is running.
 *
 * feedMedia() receives a FeedContext rather than the bare snapshot array
 * so the contract can grow by accessor — feed() (issue #3), model() (issue
 * #4) — without a parameter that breaks every implementation. This
 * interface freezes only the part we are confident about; everything still
 * open arrives on the context or as a slot on FeedMedia.
 *
 * Concerns\InteractsWithFeed answers both halves, so `implements Feedable`
 * + `use InteractsWithFeed` is a working model with no feed code: toFeed()
 * runs the model's optional describeFeed() and guesses a label it didn't
 * set; feedMedia() runs the closure registered with
 * `static::feedMediaUsing()` in `booted()`, or returns null. Writing either
 * method by hand still wins.
 *
 * A model the app doesn't own can't implement this. It's registered with
 * `Storyfeed::feedable(Media::class)->toFeedUsing(...)->feedMediaUsing(...)`
 * instead, and core treats it as Feedable everywhere (Support\Feedables).
 */
interface Feedable
{
    /**
     * The feed entity representation of this model (label + cacheable data).
     */
    public function toFeed(): FeedEntity;

    /**
     * Resolve this entity's live media at read time, from its cached snapshot.
     * Return null for entities that are not independently linkable — an
     * honest and common answer, not a gap.
     *
     * DEGRADATION. Called only for entities that have a snapshot, so
     * `$context->data('id')` is never read against nothing. A throw is
     * reported and the entity arrives with `url: null` and `media: null`;
     * a missing snapshot value reads as null through `$context->data()`
     * rather than warning. One broken resolver never breaks a feed.
     *
     * CHEAP AND SIDE-EFFECT-FREE. This may be called for entities that are
     * never rendered as links: a group node's `sample` goes through the
     * same presenter path as a singular entity, so a grouped feed can resolve
     * URLs it never paints. Nothing here should write, or assume the
     * result will be shown — it is a pure function of the context, and a
     * slow one is paid for on every node of every page. The one sanctioned
     * database touch is `$context->model()`, which is batched per class
     * across the page so that it costs one query however many entities
     * ask; read its docblock before calling it, and never reach for it to
     * read what toFeed() already cached.
     *
     * `default => null` IS NOT OPTIONAL. A resolver that matches on
     * `$context->feed()` sees null for every ad-hoc builder and always in
     * the AS2 serializer, and a `match` with no default arm throws an
     * UnhandledMatchError there — which is reported and degrades to no
     * link, silently, on exactly the surfaces you did not think about.
     * Write the arm; decide what it returns.
     *
     * PER-FEED AUTHORITY. The URL you return is correct for the feed named
     * in the context and for no other. An editorial feed's payload may carry a
     * signed operational link that must never be served on the reader
     * feed; the read path keeps them apart because the feed name is
     * declared, never sniffed. Cache a payload per feed, never across them.
     * docs/payload.md carries the full statement.
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia;
}
