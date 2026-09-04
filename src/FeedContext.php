<?php

namespace Storyfeed;

/**
 * Everything the read path knows about an entity at the moment a resolver
 * is asked for its media — handed to HasFeedMedia::feedMedia() in place of
 * the bare snapshot array toFeedLink() receives.
 *
 * The object exists so the contract can grow. Adding a parameter to an
 * interface method breaks every implementation; adding an accessor here
 * breaks none. The feed being read and a lazily hydrated model are both
 * planned to arrive as accessors (issues #3 and #4), which is why nothing
 * in here is derived from anything but the snapshot yet, and why the
 * constructor takes named arguments with defaults: a future argument must
 * not reorder today's.
 *
 * Final and readonly on purpose. A subclass could be broken by a new
 * accessor; a value that cannot be mutated cannot be mutated behind the
 * resolver's back either.
 */
final readonly class FeedContext
{
    /**
     * @param  string  $type  the entity's morph alias, exactly as stored on the activity
     * @param  array<array-key, mixed>  $data  the cached snapshot data from toFeed()
     */
    public function __construct(
        private string $type,
        private int|string|null $id = null,
        private ?string $label = null,
        private array $data = [],
    ) {}

    /**
     * The morph alias, not the class — compare it with getMorphClass().
     */
    public function type(): string
    {
        return $this->type;
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    /**
     * The cached label from the snapshot. A resolver returning its own
     * label overrides this one on the payload.
     */
    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * The snapshot data, or one value from it. A missing key degrades to
     * the default rather than warning: the read path never breaks a feed
     * over one entity, and a naive `$data['id']` was the exact shape of
     * the bug that rule came from (journal 014).
     *
     * @return ($key is null ? array<array-key, mixed> : mixed)
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? $default;
    }
}
