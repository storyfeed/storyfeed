<?php

namespace Storyfeed\Serialization;

use Closure;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\Context;
use Storyfeed\ActivityStreams\CoreType;
use Storyfeed\ActivityStreams\Property;
use Storyfeed\FeedContext;
use Storyfeed\FeedImage;
use Storyfeed\FeedResource;
use Storyfeed\FeedThread;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\LinkResolver;
use Throwable;

/**
 * Serializes an Activity as an AS2.0 JSON-LD document
 * (docs/activity-streams.md). Storage stays Laravel-native; conformance
 * lives entirely here, at the boundary.
 *
 * Rules that keep the documents spec-valid:
 *  - The AS2 `type` is DERIVED from the verb registry, never stored; the
 *    app verb always rides along as `sf:verb`, so Storyfeed→Storyfeed
 *    round-trips are lossless and foreign consumers degrade to the mapped
 *    (or base) type.
 *  - A verb mapped to an intransitive type (Arrive/Travel/Question) on an
 *    activity that carries an object emits base `Activity` and KEEPS the
 *    object — degrade, never drop.
 *  - Entities embed from snapshots; entities without snapshots serialize
 *    as bare references. Presentation extras (glyph, component, templates)
 *    never appear — they are meaningless to a federation peer. The
 *    SENTENCE the template produces is not an extra: it is AS2's own
 *    `summary`, and travels flattened (see summary()).
 */
class ActivitySerializer
{
    /**
     * Was a hand-copied duplicate of Context::DEFAULT — which nothing used,
     * so the two could have drifted silently. One source now.
     */
    public const CONTEXT = Context::DEFAULT;

    public function __construct(
        protected StoryfeedManager $storyfeed,
    ) {}

    /**
     * @param  LinkResolver|null  $links  the scope a throwing resolver is
     *                                    reported once within — one document
     *                                    unless a caller passes its own
     * @return array<string, mixed>
     */
    public function activity(Activity $activity, bool $root = true, ?LinkResolver $links = null): array
    {
        $document = $root ? ['@context' => self::CONTEXT] : [];

        // THE SCOPE IS THE DOCUMENT, AND IT IS AN ARGUMENT (issue #9). A
        // resolver that throws for its whole class is reported once here
        // rather than once per role, and CollectionSerializer passes one
        // scope across the page of documents it builds — a page of a
        // hundred activities about one broken class writes one report, not
        // four hundred. It is threaded rather than held on `$this` because
        // this serializer is resolved from the container: a memo stored on
        // a singleton would outlive the document that filled it and silence
        // the next one, which is a different bug from the one being fixed.
        $links ??= new LinkResolver;

        return [
            ...$document,
            'id' => $this->iri($activity),
            'type' => $this->type($activity),
            'sf:verb' => $activity->verb,
            ...array_filter([
                Property::Summary->value => $this->summary($activity),
                'actor' => $this->entity($activity->actor_type, $activity->cachedActor, $links, actor: true),
                'object' => $this->collectionObject($activity, $links)
                    ?? $this->entity($activity->object_type, $activity->cachedObject, $links),
                'target' => $this->entity($activity->target_type, $activity->cachedTarget, $links),
                'context' => $this->entity($activity->context_type, $activity->cachedContext, $links),
                Property::Replies->value => $this->replies($activity),
            ], fn (mixed $value) => $value !== null),
            'published' => $activity->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * The conversation around what this activity is about, as AS2's own
     * `replies` — a Collection carrying `totalItems`.
     *
     * ONE HALF OF FeedThread TRAVELS, AND ONLY ONE. `replies` is an AS2
     * property with exactly this meaning, so the count needs no translation
     * and no extension term. The UTTERANCE does not travel: `content`
     * belongs to an OBJECT, and `thread.text` is an activity's presentation
     * of an object — a peer reading `content` on this document would take it
     * for the activity's own body, which it is not. `by`, `kind` and
     * `truncated` are presentation of the same kind and stay behind with it.
     *
     * NO `sf:` TERM WAS MINTED. `ns.storyfeed.dev` is add-only forever, and
     * a term named for a shape we are still learning is a permanent
     * commitment to this week's spelling. A conservative document that omits
     * a fact is repairable; a published term is not.
     *
     * The Collection carries no `items`: the count is what is known, the
     * responses themselves are the app's and this package never held them.
     * `totalItems` without `items` is spec-legal — a Collection may be
     * described without being enumerated.
     *
     * @return array<string, mixed>|null null when nobody counted
     */
    protected function replies(Activity $activity): ?array
    {
        $data = $activity->data;

        $thread = FeedThread::fromArray(is_array($data) ? ($data[FeedThread::KEY] ?? null) : null);

        if ($thread?->replies === null) {
            return null;
        }

        return [
            'type' => CoreType::Collection->value,
            Property::TotalItems->value => $thread->replies,
        ];
    }

    /**
     * The activity as a sentence — AS2's `summary`, "a natural language
     * summarization of the object encoded as HTML", which core §4.1.1 wants
     * as the fallback text a generic client shows. The spec's own first two
     * examples are activities carrying `"summary": "Martin created an
     * image"`: exactly what the grammar produces, and until 2026-09-07
     * exactly what this document withheld. A document with `actor`, `object`
     * and `target` and no `summary` is spec-valid and reads as nothing.
     *
     * THE TOKEN GRAMMAR STAYS, AND THIS IS A FLATTENING OF IT. `summary` is
     * a flat HTML string with no way to link an entity inside it, which is
     * the reason the payload carries a template and not a sentence: roles
     * are linkable, translatable and pluralisable per node there, and none
     * of that survives here. So the payload keeps the template and the peer
     * gets the sentence; nothing in the payload changed to emit this.
     *
     * The labels are substituted server-side, at the boundary, from the same
     * snapshots the document embeds — no renderer exists in core and none is
     * introduced; this is a regex. Where the grammar entry is a closure the
     * rendered headline is what exists and is what travels.
     *
     * ABSENT, NEVER PARTIAL. A `summary` reads as complete to a peer, and
     * ":actor confirmed Delivery #1042" would be presented as prose. So a
     * token that cannot be filled — a role the row does not carry, an
     * anonymous actor, a snapshot that has not landed, a plural or invented
     * token in a singular template — withholds the key entirely: the
     * document is then exactly what it was before this method existed. The
     * payload's answer to the same gap is different, and right there: its
     * renderer turns a null-labelled role into a neutral placeholder, and it
     * has a locale to do it in. This document has neither.
     *
     * ONE LANGUAGE, THE AUTHOR'S. Templates are emitted raw by contract
     * (Story::headline(): "i18n belongs in the renderer"), so core holds one
     * spelling of each sentence and no translated variants — there is
     * nothing to put in a `summaryMap`, and minting one from a single
     * string would assert a language the package cannot know. A consumer
     * that registers translated grammar gets a translated `summary`.
     *
     * ENCODED AS HTML, as the term's definition says. Templates are plain
     * strings and labels are user data, so the finished sentence is escaped
     * once, whole: a label of `<b>` reaches the peer as `&lt;b&gt;`, and an
     * author's `&` becomes `&amp;`, which is the correct spelling of a plain
     * sentence in HTML.
     */
    protected function summary(Activity $activity): ?string
    {
        $entry = $this->storyfeed->template($activity->object_type, $activity->verb);

        if ($entry === null) {
            return null;
        }

        if ($entry instanceof Closure) {
            try {
                $sentence = (string) $entry($activity);
            } catch (Throwable $e) {
                // Same posture as the payload presenter: an authoring bug is
                // reported, never a broken document.
                report($e);

                return null;
            }

            return $sentence === '' ? null : e($sentence);
        }

        $labels = [
            'actor' => $activity->cachedActor?->label,
            'object' => $activity->cachedObject?->label,
            'target' => $activity->cachedTarget?->label,
            'context' => $activity->cachedContext?->label,
        ];

        $complete = true;

        // One pass over the template, never over its output: a label is
        // never re-scanned, so "Re:actor" in a delivery's name cannot be
        // taken for a token. `[a-z]+` is greedy on purpose — `:actors` is
        // not `:actor` followed by an s, it is a token this sentence has no
        // label for, and the guard below is what catches it.
        $sentence = (string) preg_replace_callback(
            '/:([a-z]+)/',
            function (array $match) use ($labels, &$complete): string {
                $label = $labels[$match[1]] ?? null;

                if (! is_string($label) || $label === '') {
                    $complete = false;

                    return $match[0];
                }

                return $label;
            },
            $entry,
        );

        return $complete ? e($sentence) : null;
    }

    public function iri(Activity $activity): string
    {
        $prefix = trim((string) config('storyfeed.routes.prefix', 'storyfeed'), '/');

        return url("{$prefix}/activities/{$activity->uid}");
    }

    /**
     * The AS2 type for the activity. Unmapped verbs emit the base
     * `Activity` type (spec-legal); so does an intransitive mapping that
     * conflicts with a present object.
     */
    protected function type(Activity $activity): string
    {
        $type = $this->storyfeed->activityType($activity->verb);

        if ($type instanceof ActivityType && $type->isIntransitive() && $activity->object_type !== null) {
            return 'Activity';
        }

        return $this->storyfeed->activityTypeValue($activity->verb);
    }

    /**
     * A composite parent's object is an OrderedCollection — the one
     * aggregate AS2 natively supports, and the reason composites exist at
     * the serialization boundary: "uploaded 6 files" is one Activity whose
     * object is a collection of six, each member entity embedded live from
     * its snapshot, reverse-chronological.
     *
     * @return array<string, mixed>|null null for non-composite activities
     */
    protected function collectionObject(Activity $activity, LinkResolver $links): ?array
    {
        if ($activity->object_type !== null) {
            return null;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $memberIds = $grouping::query()
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->where('winner', true)
            ->pluck('activity_id');

        if ($memberIds->isEmpty()) {
            return null;
        }

        $model = config('storyfeed.models.activity', Activity::class);

        $members = $model::query()
            ->whereKey($memberIds)
            ->with('cachedObject')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        return [
            'type' => 'OrderedCollection',
            'totalItems' => $members->count(),
            'orderedItems' => $members
                ->map(fn (Activity $member) => $this->entity($member->object_type, $member->cachedObject, $links))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * An embedded entity object, or a bare reference when un-snapshotted.
     * Null when the role is empty.
     *
     * @return array<string, mixed>|null
     */
    protected function entity(?string $alias, ?Snapshot $snapshot, LinkResolver $links, bool $actor = false): ?array
    {
        if ($alias === null) {
            return null;
        }

        $data = $snapshot->data ?? [];

        // A Party row carries its own AS2 type (Service for integrations,
        // Application for the app itself); it wins over the class default.
        $type = $this->entityType($alias, $data);

        // No snapshot ⇒ bare reference, no link regeneration (same rule as
        // the payload presenter: a resolver is never called with empty data).
        //
        // No feed, on purpose. A federation document describes an activity,
        // not a surface, and must read the same whoever fetched it — so the
        // resolver is told there is no feed and answers from its default arm.
        //
        // NO BATCH, on purpose too. This serializes one activity at a time,
        // so there is no page to seed an identity map from: a resolver that
        // calls $context->model() here pays one query per entity, where the
        // payload presenter pays one per class per page. Correct, not
        // amortised — do not benchmark this endpoint against a feed page
        // with a hydrating resolver and report the difference as a
        // regression. A resolver that must stay query-free on this path can
        // branch on feed() === null, which is always true here.
        $media = $snapshot === null ? null : $links->resolve(new FeedContext(
            type: $alias,
            id: $snapshot->model_id,
            label: $snapshot->label,
            data: $data,
            feed: null,
        ));
        $href = $media?->href();
        $absolute = $href === null ? null : url($href);

        return array_filter([
            'type' => $type,
            // The entity's IRI, when the host app can mint one. Emitted as
            // `id` for actors (AS2 actors want identity) and `url` for the
            // other roles, matching the document-shape examples.
            'id' => $actor ? $absolute : null,
            Property::Name->value => $snapshot?->label,
            // A url the resolver typed as an image becomes a Link object so
            // its mediaType and dimensions travel; a plain href stays the
            // bare string it always was. Both are legal values for as:url.
            Property::Url->value => match (true) {
                $actor => null,
                $media?->url instanceof FeedImage => $this->link($media->url),
                default => $absolute,
            },
            // The remaining slots are AS2's own properties with AS2's own
            // meanings, which is the whole reason FeedMedia names them that
            // way — nothing to translate, only to spell out.
            Property::Icon->value => $this->link($media?->icon),
            Property::Image->value => $this->link($media?->image),
            Property::Preview->value => $this->link($media?->preview),
            'attachment' => $media?->attachment === null ? null : [
                'type' => $media->attachment->type,
                Property::Url->value => $this->link($media->attachment),
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * A FeedImage as an AS2 Link object.
     *
     * One shape for every slot, chosen because it is the one shape all four
     * accept: `url` takes a Link, `preview` takes Object|Link, `icon` and
     * `image` take Image|Link. A Link is also the only AS2 term that carries
     * `width` and `height` in the REC, which is where a renderer's aspect
     * reservation comes from. `src` and `alt` are spelled `href` and `name`
     * here and nowhere else; absent facts are absent keys, never null, so a
     * peer reading `width` cannot mistake "unknown" for a value.
     *
     * @return array<string, mixed>|null
     */
    protected function link(FeedImage|FeedResource|null $image): ?array
    {
        if ($image === null) {
            return null;
        }

        if ($image instanceof FeedResource) {
            return array_filter([
                'type' => CoreType::Link->value,
                Property::Href->value => url($image->href),
                Property::MediaType->value => $image->mediaType,
                Property::Name->value => $image->name,
            ], fn ($value) => $value !== null);
        }

        return array_filter([
            'type' => CoreType::Link->value,
            Property::Href->value => url($image->src),
            Property::MediaType->value => $image->mediaType,
            Property::Name->value => $image->alt,
            Property::Width->value => $image->width,
            Property::Height->value => $image->height,
        ], fn ($value) => $value !== null);
    }

    /**
     * Only a Party's snapshot carries a per-row AS2 type — a domain model's
     * snapshot may have its own `type` field meaning something else
     * entirely, so the override is scoped to the party alias.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function entityType(string $alias, array $data): string
    {
        if ($alias === config('storyfeed.morph_alias', 'storyfeed.party')) {
            $own = $data['type'] ?? null;

            if (is_string($own) && $own !== '') {
                return $own;
            }
        }

        return $this->storyfeed->objectTypeValue($alias);
    }
}
