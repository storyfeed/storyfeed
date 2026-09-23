<?php

namespace Storyfeed;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A model you don't own, made Feedable from a service provider:
 *
 *     Storyfeed::feedable(Media::class)
 *         ->toFeedUsing(fn (Media $media, FeedEntity $entity) => $entity->label($media->name))
 *         ->feedMediaUsing(fn (FeedContext $context, FeedMedia $media) => $media->url(...));
 *
 * The two closures are the two halves of the Feedable contract, with the
 * model passed in rather than `$this`, because it isn't your class. Both are
 * optional: with neither, the model is snapshotted under its guessed label
 * and is never linkable.
 *
 * Registration is by EXACT class. Eloquent fires a model's events under its
 * own class name, so a subclass registered through its parent would snapshot
 * on publish but never hear its own saves or deletes. Register the class the
 * vendor (or your config) actually instantiates.
 *
 * @template TModel of Model
 */
final class FeedableRegistration
{
    /** @var (Closure(TModel, FeedEntity): mixed)|null */
    private ?Closure $toFeed = null;

    /** @var (Closure(FeedContext, FeedMedia): (FeedMedia|string|null))|null */
    private ?Closure $feedMedia = null;

    /** @param class-string<TModel> $class */
    public function __construct(public readonly string $class) {}

    /**
     * Describe the stored half: the closure receives the model and a fresh
     * entity. Return the entity (a chain does) or nothing; a label left
     * unset is guessed.
     *
     * @param  Closure(TModel, FeedEntity): mixed  $describe
     * @return $this
     */
    public function toFeedUsing(Closure $describe): self
    {
        $this->toFeed = $describe;

        return $this;
    }

    /**
     * Resolve the read-time half. Return a URL string, the `$media` you were
     * handed, or null for "not linkable".
     *
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     * @return $this
     */
    public function feedMediaUsing(Closure $resolver): self
    {
        $this->feedMedia = $resolver;

        return $this;
    }

    /**
     * Whether `toFeedUsing()` describes it; without it, its label is guessed.
     *
     * @internal
     */
    public function describesFeed(): bool
    {
        return $this->toFeed !== null;
    }

    /**
     * @param  TModel  $model
     *
     * @internal
     */
    public function toFeed(Model $model): FeedEntity
    {
        $entity = FeedEntity::make();

        if ($this->toFeed !== null) {
            $described = ($this->toFeed)($model, $entity);
            $entity = $described instanceof FeedEntity ? $described : $entity;
        }

        return $entity->label === null
            ? $entity->label(app(Support\Feedables::class)->guessLabel($model))
            : $entity;
    }

    /** @internal */
    public function feedMedia(FeedContext $context): ?FeedMedia
    {
        return $this->feedMedia === null ? null : Support\Feedables::resolveMedia($this->feedMedia, $context);
    }
}
