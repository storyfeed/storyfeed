<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Support\ModelHydrator;

/**
 * Everything the read path knows about an entity at the moment a resolver
 * is asked for its media — handed to Feedable::feedMedia() in place of the
 * bare snapshot array the retired toFeedLink() used to receive.
 *
 * The object exists so the contract can grow. Adding a parameter to an
 * interface method breaks every implementation; adding an accessor here
 * breaks none. The feed being read arrived that way (issue #3, feed()),
 * and so did the lazily hydrated model (issue #4, model()). Each is one
 * constructor argument appended after the last, which is why the
 * constructor takes named arguments with defaults: a future argument must
 * not reorder today's, and a caller that does not know about it must not
 * have to.
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
     * @param  ModelHydrator  $hydrator  the page's identity map; a context built
     *                                   without one gets a private map and
     *                                   model() becomes a single lookup
     */
    public function __construct(
        private string $type,
        private int|string|null $id = null,
        private ?string $label = null,
        private array $data = [],
        private ?string $feed = null,
        private ModelHydrator $hydrator = new ModelHydrator,
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

    /**
     * The live model behind this entity — or null.
     *
     * THIS IS THE ONE PLACE A RESOLVER MAY TOUCH THE DATABASE, and it costs
     * what it says: nothing until called, one query per class on the page
     * once it is. It is an accessor and not a parameter on purpose. A
     * signature that quietly hydrated would make a page slower with nothing
     * at the call site to say so; a call announces the cost where it is
     * paid, and a signature could not express `with:` anyway. Use it when
     * the snapshot genuinely cannot carry what the link needs — a policy
     * check, a relation, a value that changes too often to trickle. Do not
     * use it to read what toFeed() already cached; that is what data() is for.
     *
     * BATCHED, NOT N+1. The presenter seeds the page's identity map with
     * every (type, id) it holds before any resolver runs, so the first
     * Customer to ask loads every Customer on the page in one whereKey()
     * and every later Customer is a map hit. Ten classes is ten queries
     * whether the page has twenty nodes or two hundred. The AS2 serializer
     * resolves one activity at a time and has no page to seed from, so
     * there a call is a single lookup — correct, only not amortised.
     *
     *     $model = $context->model();                       // batched on first use, per class
     *     $model = $context->model(with: ['engagement']);   // relations ride the same batch
     *
     * NESTED ACCESS IS STILL A FOOTGUN. `$context->model()->customer->name`
     * is an N+1 inside a hydrated model and invisible to the batch — the map
     * loaded Deliveries, not their Customers. That is what `with:` is for:
     * relations named there are eager loaded across the whole class. Named
     * on a later call than the first, they load once across every model
     * already in the map, not once per model, so the order of asking does
     * not change the count.
     *
     * NULL IS THE ANSWER FOR EVERY WAY THIS CAN NOT RESOLVE, and the read
     * path never throws through here. Row gone: null — the snapshot still
     * renders, the slot is simply not linked. Soft-deleted: null by default,
     * because a link to a page that 500s is worse than no link; pass
     * `withTrashed: true` to opt in, on classes that soft-delete. Alias
     * that resolves to nothing, id the snapshot never carried, a batch that
     * threw (reported, once): null. Hydration switched off in config
     * (`storyfeed.hydration.enabled`), for a surface that needs a
     * no-queries guarantee: null, silently. A resolver that calls this has
     * therefore always written its null branch — the same `default =>` arm
     * it already needed for feed().
     *
     * KNOWN CONSEQUENCE, NOT A BUG. The entity's label comes from its
     * snapshot; a link minted from the live model comes from now. The two
     * can disagree — a row reading with the name from before a rename while
     * linking to the record as it is today. Fresh and stale in one sentence.
     * A resolver that has paid for the model can close that gap itself by
     * returning FeedMedia with a `label:` from the model, which overrides
     * the snapshot's on the node. What it must not do is write: this runs
     * for exemplars that are never painted, and it is read-time.
     *
     * @param  array<int|string, mixed>  $with  relations to eager load with the batch, in the shape Builder::with() accepts
     * @param  bool  $withTrashed  include soft-deleted rows; ignored on classes that do not soft-delete
     */
    public function model(array $with = [], bool $withTrashed = false): ?Model
    {
        return $this->hydrator->model($this->type, $this->id, $with, $withTrashed);
    }
}
