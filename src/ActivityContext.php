<?php

namespace Storyfeed;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\InteractsWithData;
use Storyfeed\Support\DataCasts;

/**
 * The recorded facts available to a headline closure: activity data and
 * the cached entities for its roles, without exposing the Activity model.
 *
 * Final and readonly like FeedContext. Named constructor arguments have
 * defaults so new facts can be added without reordering existing callers.
 * Data helpers come directly from Laravel, with its request semantics.
 *
 * CASTS are the verb's `casts()`, Eloquent's: get() reads a cast key
 * through its cast, as `$model->getAttribute()` does, while all() and the
 * typed helpers read the recorded values, as `getAttributes()` and the
 * request's helpers do. A helper is a cast of its own (`enum()`, `date()`),
 * so it is handed what was recorded, never another cast's result.
 */
final readonly class ActivityContext
{
    use InteractsWithData;

    /**
     * @param  array<array-key, mixed>  $data  the activity's recorded data
     * @param  array<string, mixed>  $casts  the verb's Eloquent casts, keyed by data key
     */
    public function __construct(
        private array $data = [],
        private string $verb = '',
        private ?CarbonImmutable $publishedAt = null,
        private ?FeedContext $actor = null,
        private ?FeedContext $object = null,
        private ?FeedContext $target = null,
        private ?FeedContext $context = null,
        private ?FeedContext $origin = null,
        private ?FeedContext $result = null,
        private ?FeedContext $instrument = null,
        private array $casts = [],
    ) {}

    /**
     * All activity data, or selected keys, as on Laravel's Fluent.
     *
     * @param  mixed  $keys
     * @return array<array-key, mixed>
     */
    public function all($keys = null): array
    {
        $data = $this->data();

        if (! $keys) {
            return $data;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($data, $key));
        }

        return $results;
    }

    /**
     * One data value, using "dot" notation, as on Laravel's Fluent::get().
     *
     * A key the verb casts is read through its cast first, and the rest of
     * the path is read from the result: `get('order.total')` is the total
     * of the cast `order`.
     *
     * @param  string  $key
     * @param  mixed  $default
     */
    public function get($key, $default = null): mixed
    {
        [$first, $rest] = explode('.', (string) $key, 2) + [1 => null];

        if (! array_key_exists($first, $this->casts) || ! array_key_exists($first, $this->data)) {
            return data_get($this->data, $key, $default);
        }

        /** @var array<string, mixed> $data */
        $data = $this->data;
        $value = DataCasts::get($data, $this->casts, $first);

        return $rest === null ? $value : data_get($value, $rest, $default);
    }

    /**
     * The data source for Laravel's typed helpers, as on Fluent.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? array<array-key, mixed> : mixed)
     */
    protected function data($key = null, $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /** The recorded verb. */
    public function verb(): string
    {
        return $this->verb;
    }

    /** The publication time, immutable like FeedItem::publishedAt(). */
    public function publishedAt(): ?CarbonImmutable
    {
        return $this->publishedAt;
    }

    /** The actor's cached entity, or null when the role is empty. */
    public function actor(): ?FeedContext
    {
        return $this->actor;
    }

    /** The object's cached entity, or null when the role is empty. */
    public function object(): ?FeedContext
    {
        return $this->object;
    }

    /** The target's cached entity, or null when the role is empty. */
    public function target(): ?FeedContext
    {
        return $this->target;
    }

    /** The context's cached entity, or null when the role is empty. */
    public function context(): ?FeedContext
    {
        return $this->context;
    }

    /** The origin's cached entity, or null when the role is empty. */
    public function origin(): ?FeedContext
    {
        return $this->origin;
    }

    /** The result's cached entity, or null when the role is empty. */
    public function result(): ?FeedContext
    {
        return $this->result;
    }

    /** The instrument's cached entity, or null when the role is empty. */
    public function instrument(): ?FeedContext
    {
        return $this->instrument;
    }
}
