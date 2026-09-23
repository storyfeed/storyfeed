<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Storyfeed\Actions\RestoreToFeed;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedableRegistration;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\FeedNoun;
use Storyfeed\StoryfeedManager;
use Stringable;

/**
 * Which models are Feedable, and how to ask one for its two halves.
 *
 * A model is Feedable when it implements the contract, or when a service
 * provider registered it with `Storyfeed::feedable()` because it's a class
 * the app doesn't own. Every place in core that used to test
 * `instanceof Feedable` asks isFeedable() here, and every place that called
 * `toFeed()` or `::feedMedia()` goes through toFeed() and feedMedia(), so a
 * registered class is treated the same everywhere.
 *
 * It also keeps the `feedMediaUsing()` closures models register in
 * `booted()`, and the app-wide label guesser. A container singleton rather
 * than state on the manager, so `Storyfeed::fake()` swapping the manager
 * keeps every registration.
 */
class Feedables
{
    /** @var array<class-string<Model>, FeedableRegistration<Model>> */
    private array $registrations = [];

    /** @var array<class-string<Model>, Closure(FeedContext, FeedMedia): (FeedMedia|string|null)> */
    private array $mediaResolvers = [];

    /** @var (Closure(Model): ?string)|null */
    private ?Closure $labelGuesser = null;

    /**
     * Treat a class you don't own as Feedable. Calling it again for the same
     * class returns the same registration.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return FeedableRegistration<TModel>
     */
    public function register(string $class): FeedableRegistration
    {
        if (isset($this->registrations[$class])) {
            /** @var FeedableRegistration<TModel> */
            return $this->registrations[$class];
        }

        if (is_a($class, Feedable::class, true)) {
            throw new InvalidArgumentException(
                "[{$class}] already implements Feedable, so it can't also be registered with Storyfeed::feedable(). "
                .'Describe it in the class (describeFeed(), toFeed() or feedMedia()), or remove the interface.'
            );
        }

        $this->listen($class);

        return $this->registrations[$class] = new FeedableRegistration($class);
    }

    /**
     * The lifecycle the InteractsWithFeed trait wires for its own models,
     * wired on a class that can't use the trait. Listened to through the
     * dispatcher by event name, which works from a provider's register() as
     * well as boot(); `$class::saved()` would be dropped silently before the
     * database provider has set the model dispatcher.
     *
     * @param  class-string<Model>  $class
     */
    private function listen(string $class): void
    {
        $events = app(Dispatcher::class);

        $events->listen("eloquent.saved: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new SnapshotEntity)($model);
            }
        });

        $events->listen("eloquent.deleted: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new TombstoneEntity)($model);
            }
        });

        // Both fire only for a class using SoftDeletes; harmless otherwise.
        $events->listen("eloquent.forceDeleted: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new TombstoneEntity)->forceDeleted($model);
            }
        });

        $events->listen("eloquent.restored: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new RestoreToFeed)($model);
            }
        });
    }

    /**
     * The registration for exactly this class, if any.
     *
     * @param  Model|class-string  $class
     * @return FeedableRegistration<Model>|null
     */
    public function registration(Model|string $class): ?FeedableRegistration
    {
        return $this->registrations[$class instanceof Model ? $class::class : $class] ?? null;
    }

    /** @param  Model|class-string|null  $class */
    public function isFeedable(Model|string|null $class): bool
    {
        return $class !== null
            && (is_a($class, Feedable::class, true) || $this->registration($class) !== null);
    }

    /** @return list<class-string<Model>> */
    public function registered(): array
    {
        return array_keys($this->registrations);
    }

    /** The stored half, from the class or from its registration. */
    public function toFeed(Model $model): FeedEntity
    {
        if ($model instanceof Feedable) {
            return $model->toFeed();
        }

        return ($this->registration($model) ?? throw new LogicException(
            'Model ['.$model::class.'] is not Feedable: implement the contract or register it with Storyfeed::feedable().'
        ))->toFeed($model);
    }

    /**
     * The read-time half. Null for a class that is not Feedable at all.
     *
     * @param  class-string  $class
     */
    public function feedMedia(string $class, FeedContext $context): ?FeedMedia
    {
        if (is_a($class, Feedable::class, true)) {
            return $class::feedMedia($context);
        }

        return $this->registration($class)?->feedMedia($context);
    }

    /**
     * Keep a model's `feedMediaUsing()` closure. A second registration for
     * the same class REPLACES the first, so a model booted twice (after
     * `Model::clearBootedModels()`) still has one resolver.
     *
     * @param  class-string<Model>  $class
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     */
    public function useMediaResolver(string $class, Closure $resolver): void
    {
        $this->mediaResolvers[$class] = $resolver;
    }

    /**
     * @param  class-string<Model>  $class
     * @return (Closure(FeedContext, FeedMedia): (FeedMedia|string|null))|null
     */
    public function mediaResolver(string $class): ?Closure
    {
        return $this->mediaResolvers[$class] ?? null;
    }

    /**
     * Call a `feedMediaUsing()` closure: a string is the URL, a FeedMedia is
     * used as it is, and null means not linkable.
     *
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     */
    public static function resolveMedia(Closure $resolver, FeedContext $context): ?FeedMedia
    {
        $media = FeedMedia::make();
        $resolved = $resolver($context, $media);

        return is_string($resolved) ? $media->url($resolved) : $resolved;
    }

    /** @param  (Closure(Model): ?string)|null  $guesser */
    public function guessLabelsUsing(?Closure $guesser): void
    {
        $this->labelGuesser = $guesser;
    }

    /**
     * A label for a model whose feed code set none: the app-wide guesser if
     * it answers, then the ladder — a `name` or `title` attribute, the
     * registered noun and the key ("Dish #42"), the class name and the key
     * ("Menu Item #42").
     */
    public function guessLabel(Model $model): string
    {
        if ($this->labelGuesser !== null && ($label = ($this->labelGuesser)($model)) !== null) {
            return $label;
        }

        foreach (['name', 'title'] as $attribute) {
            if (($label = $this->attributeLabel($model, $attribute)) !== null) {
                return $label;
            }
        }

        $noun = app(StoryfeedManager::class)->registeredNouns()[$model->getMorphClass()] ?? null;

        $words = $noun === null
            ? Str::headline(class_basename($model))
            : Str::ucfirst(FeedNoun::form($noun, 1));

        return $model->getKey() === null ? $words : "{$words} #{$model->getKey()}";
    }

    /**
     * An attribute's value when the model has one to give — a column that
     * was selected, or an accessor — without tripping
     * `Model::preventAccessingMissingAttributes()` on a model that has
     * neither.
     */
    private function attributeLabel(Model $model, string $attribute): ?string
    {
        if (! array_key_exists($attribute, $model->getAttributes())
            && ! $model->hasGetMutator($attribute)
            && ! $model->hasAttributeGetMutator($attribute)) {
            return null;
        }

        $value = $model->getAttribute($attribute);

        if (! (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
