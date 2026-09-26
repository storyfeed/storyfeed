<?php

namespace Storyfeed\Concerns;

use Closure;
use Storyfeed\Actions\DeleteFromFeed;
use Storyfeed\Actions\ForceDeleteFromFeed;
use Storyfeed\Actions\RestoreToFeed;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\MediaSlot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;

/**
 * Keeps a Feedable model's presence in the feed in sync with its lifecycle —
 * refreshes its snapshot on save, leaves a tombstone on delete and takes it
 * back on restore — and gives the model a feed of its own.
 *
 * It also answers the whole Feedable contract, so a model needs no feed code
 * to be valid:
 *
 *     class Dish extends Model implements Feedable
 *     {
 *         use InteractsWithFeed;
 *     }
 *
 * is snapshotted as "Carrot Soup" (its `name`), or "Ticket #42", and is never a
 * link. The two halves are refined separately, and the file's shape shows
 * which is which — what's stored is an instance method, what's resolved at
 * read time is a closure registered in `booted()`, because at read time
 * there's no model:
 *
 *     public function describeFeed(): void
 *     {
 *         $this->feedEntity()->label("Dish #{$this->number}")->body(...);
 *     }
 *
 *     protected static function booted(): void
 *     {
 *         static::feedMediaUsing(fn ($context) => route('dishes.show', $context->routeKey()));
 *     }
 *
 * Neither needs a FeedEntity, FeedContext or FeedMedia import. A
 * hand-written `toFeed()` or `feedMedia()` on the model still wins, because
 * a class's own method beats its trait's.
 */
trait InteractsWithFeed
{
    public static function bootInteractsWithFeed(): void
    {
        // The lifecycle half of the recording switch. feed_snapshots is the
        // highest-churn feed table because this hook fires for EVERY save of
        // every Feedable model, activity or not — one consumer's parallel
        // suite hit Postgres autovacuum deadlocks on exactly that table. The
        // explicit call below is deliberately not gated: a method does what
        // its name says; only the automatic write is muted.
        static::saved(function ($model) {
            if (app(StoryfeedManager::class)->isRecording()) {
                $model->updateFeedSnapshot();
            }
        });

        // A deleted model leaves a tombstone, and its stories survive. The
        // activities themselves are only deleted when asked, with
        // deleteFromFeed() or forceDeleteFromFeed(). Muted like the saved
        // hook: with recording off the trickle catches up later.
        static::deleted(function ($model) {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new TombstoneEntity)($model);
            }
        });

        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function ($model) {
                if (app(StoryfeedManager::class)->isRecording()) {
                    (new TombstoneEntity)->forceDeleted($model);
                }
            });
        }

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                if (app(StoryfeedManager::class)->isRecording()) {
                    (new RestoreToFeed)($model);
                }
            });
        }
    }

    /**
     * This model's feed: every activity it took part in, in any role.
     *
     *   $project->storyfeed()->live()->get();
     *
     * Exactly equivalent to the facade form, with the argument already filled
     * in — the same builder, so every method still applies:
     *
     *   Storyfeed::feed()->involving($project)->live()->get();
     *
     * Not to be confused with the `storyfeed()` HELPER, which returns the
     * manager, or a pending activity when given a verb. Both are reachable from
     * inside a model: `storyfeed()` is the function, `$this->storyfeed()` this.
     *
     * A named feed narrows it to one audience's verbs, declared once in a
     * service provider with `Storyfeed::feeds([...])` — see docs/feeds.md:
     *
     *   $ticket->storyfeed('ticket')->get();
     *
     * The feed's constraints apply BEFORE involving(), so the two compose: this
     * ticket's timeline, as a requester may see it.
     *
     * A Feed CLASS that takes its subject as a constructor argument cannot be
     * entered this way — it is built through its constructor instead, and the
     * role it binds is its own rather than involving():
     *
     *   TicketFeed::make($ticket)->get();
     *
     * Needs `feed_participants` populated. On an existing install that means
     * running `storyfeed:participants` once; `storyfeed:doctor` warns until it
     * has been.
     */
    public function storyfeed(?string $preset = null): FeedBuilder
    {
        return app(StoryfeedManager::class)->feed($preset)->involving($this);
    }

    public function updateFeedSnapshot(): void
    {
        (new SnapshotEntity)($this);
    }

    /**
     * The entity `describeFeed()` is building, the same instance on every
     * call within one `toFeed()`, so `$this->feedEntity()->label(...)` and a
     * later `$this->feedEntity()->body(...)` add to one entity.
     */
    protected ?FeedEntity $describedFeedEntity = null;

    /**
     * Describe this model for its snapshot: label, data, bodies.
     *
     *     public function describeFeed(): void
     *     {
     *         $this->feedEntity()
     *             ->label("Ticket #{$this->reference}")
     *             ->body(Excerpt::make()->text($this->notes));
     *     }
     *
     * Optional. Whatever it leaves unset stays empty, except the label, which
     * is guessed (see guessFeedLabel()).
     */
    public function describeFeed(): void {}

    /** The entity describeFeed() writes to. */
    public function feedEntity(): FeedEntity
    {
        return $this->describedFeedEntity ??= FeedEntity::make();
    }

    /**
     * The stored half of the contract: what describeFeed() wrote, with a
     * guessed label if it wrote none. Write `toFeed()` on the model instead
     * to build and return the entity yourself.
     */
    public function toFeed(): FeedEntity
    {
        $this->describedFeedEntity = FeedEntity::make();

        try {
            $this->describeFeed();

            $entity = $this->describedFeedEntity;
        } finally {
            $this->describedFeedEntity = null;
        }

        return $entity->label === null ? $entity->label($this->guessFeedLabel()) : $entity;
    }

    /**
     * The label a model gets when its feed code sets none. There is no
     * "fails on first save": a model with `use InteractsWithFeed` and nothing
     * else is valid, and this is its label.
     *
     * The ladder: a `name` or `title` attribute, then the registered noun and
     * the key ("Ticket #42", from `Storyfeed::nouns()`), then the class name as
     * words and the key ("Support Ticket #42"). An app-wide guesser registered with
     * `Storyfeed::guessFeedLabelsUsing()` is asked first; returning null falls
     * through to the ladder. Override this method on a model, or a base
     * model, to change it there.
     */
    public function guessFeedLabel(): string
    {
        return app(Feedables::class)->guessLabel($this);
    }

    /**
     * The read-time half of the contract: what the `feedMediaUsing()`
     * closure resolves, or null — not independently linkable — when the
     * model registered none.
     *
     * A MISSING LINK IS A STATE. Returning null here is honest and common:
     * one consumer returns it from all four of its models on purpose, because
     * the same snapshot renders on three surfaces and the right URL depends
     * on who is reading. The feed renders the entity at full weight, just
     * not clickable.
     *
     * BOOTS THE MODEL FIRST. This is static, and the read path calls it for
     * a class that may never have been instantiated in this process, so
     * `booted()` — where the closure is registered — hasn't run yet.
     * `static::query()` boots it (it instantiates the model) without
     * touching the database.
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        if (! isset(static::$booted[static::class])) {
            static::query();
        }

        $resolver = app(Feedables::class)->mediaResolver(static::class);

        return $resolver === null ? null : Feedables::resolveMedia($resolver, $context);
    }

    /**
     * Register how this model's live media is resolved at read time, from
     * `booted()`:
     *
     *     static::feedMediaUsing(fn ($context) => route('tickets.show', $context->routeKey()));
     *
     * The closure gets the entity's FeedContext and a fresh FeedMedia. Return
     * a URL string, the `$media` you filled, or null for "not linkable".
     * Registering again replaces the closure, so a re-boot never stacks them.
     *
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     */
    protected static function feedMediaUsing(Closure $resolver): void
    {
        app(Feedables::class)->useMediaResolver(static::class, $resolver);
    }

    /**
     * The name of this model's `icon` slot, for something stored to refer to.
     *
     * Three forwarding calls, one per image slot, each returning the
     * {@see MediaSlot} case of the same name. They exist for the call site:
     *
     *     data: MediaObject::make(subject: $this->name, image: $this->feedMediaIcon())
     *
     * reads as "the image is my feedMedia's icon", which is the one thing a
     * reader of `toFeed()` needs and cannot otherwise see — that the picture a
     * stored block draws is the one `feedMedia()` resolves at read time, never
     * a URL frozen into the row. `MediaSlot::Icon` says the same thing with the
     * resolver's name missing from it.
     *
     * NOT `usingFeedMediaIcon()`. A verb would hint that something is
     * resolved here, eagerly, and nothing is: this is a lazy reference to a
     * slot the resolver may fill later, or never. A block naming a slot the
     * resolver leaves empty draws nothing, silently.
     */
    public function feedMediaIcon(): MediaSlot
    {
        return MediaSlot::Icon;
    }

    /** The name of this model's `preview` slot — see {@see feedMediaIcon()}. */
    public function feedMediaPreview(): MediaSlot
    {
        return MediaSlot::Preview;
    }

    /** The name of this model's `image` slot — see {@see feedMediaIcon()}. */
    public function feedMediaImage(): MediaSlot
    {
        return MediaSlot::Image;
    }

    /**
     * Soft-delete every activity involving this model. See
     * {@see DeleteFromFeed}. Never called automatically: a deleted model
     * leaves a tombstone instead, and its activities stay.
     */
    public function deleteFromFeed(): void
    {
        (new DeleteFromFeed)($this);
    }

    /**
     * Permanently delete every activity involving this model, including
     * activities that were already soft-deleted, and everything that points
     * at them: erasure. See {@see ForceDeleteFromFeed}. Never called
     * automatically; a force-deleted model's tombstone becomes permanent.
     */
    public function forceDeleteFromFeed(): void
    {
        (new ForceDeleteFromFeed)($this);
    }
}
