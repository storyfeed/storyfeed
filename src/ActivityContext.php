<?php

namespace Storyfeed;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\InteractsWithData;

/**
 * The recorded facts available to a headline closure: activity data and
 * the cached entities for its roles, without exposing the Activity model.
 *
 * Final and readonly like FeedContext. Named constructor arguments have
 * defaults so new facts can be added without reordering existing callers.
 * Data helpers come directly from Laravel, with its request semantics.
 */
final readonly class ActivityContext
{
    use InteractsWithData;

    /** @param array<array-key, mixed> $data the activity's recorded data */
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
