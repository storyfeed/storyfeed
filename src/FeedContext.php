<?php

namespace Storyfeed;

/**
 * Everything the read path knows about an entity at the moment a resolver
 * is asked for its media — handed to Feedable::feedMedia() in place of the
 * bare snapshot array the retired toFeedLink() used to receive.
 *
 * The object exists so the contract can grow. Adding a parameter to an
 * interface method breaks every implementation; adding an accessor here
 * breaks none. The feed being read arrived that way (issue #3, feed()); a
 * lazily hydrated model is next (issue #4). Each is one constructor
 * argument appended after the last, which is why the constructor takes
 * named arguments with defaults: a future argument must not reorder
 * today's, and a caller that does not know about it must not have to.
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
        private ?string $feed = null,
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

    /**
     * The registered name of the feed being read — `'kitchen'`,
     * `'customer'` — or null when there is none to report.
     *
     * DECLARED, NEVER SNIFFED. This is the name a FeedDefinition stamped on
     * the builder the page was read through, and nothing else: not the
     * request, not the route, not a panel, not who is logged in. Feeds render
     * with no request at all (queued digests, console, the AS2 serializer,
     * tests), a Livewire poll arrives through a shared endpoint that says
     * nothing about the page, and a payload that varied by request would
     * have no stable cache key. A resolver that branches on this value is
     * therefore correct in every one of those places, or wrong in none.
     *
     * NULL IS AN ANSWER, NOT A GAP. A feed built ad hoc — `Storyfeed::feed()`,
     * `$model->storyfeed()`, `new FeedBuilder` — has no name and reports none;
     * inventing one ('default', 'global') would make an unnamed surface look
     * deliberate. The AS2 serializer reports null for a different reason: a
     * federation document has no surface, and must not vary by one. A
     * resolver's `default =>` arm is for both.
     *
     * A STRING, NOT THE DEFINITION AND NOT A CLASS. The name is what a
     * `match` reads best against and the only identity every feed has — a
     * closure preset has no class, and handing over the definition would put
     * build() and inspect() in the hands of code that should only compare.
     *
     * ONE FEED, ONE NAME, WHATEVER DOOR. A class feed registered as
     * `'kitchen' => CustomerFeed::class` reports 'kitchen' when read through
     * `Storyfeed::feed('kitchen')`, through `CustomerFeed::make($order)`, and
     * through a class-string alike. It briefly reported the class-derived
     * 'customer' on the last two, which made a resolver silently right on
     * one page and silently wrong on another — the exact failure a declared
     * surface exists to remove (journal 054, 055). The registered key wins;
     * the derived name is only what a class is called when nobody registered
     * it under anything. To survive a rename of either, compare against the
     * class rather than a literal — `CustomerFeed::name() => …` — which
     * returns this same canonical name; that is why the Feed base class
     * exposes name() statically.
     */
    public function feed(): ?string
    {
        return $this->feed;
    }
}
