<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Storyfeed\Exceptions\FeedMisconfigured;

/**
 * One class per audience — the declarative form of a named feed.
 *
 *   class CustomerFeed extends Feed
 *   {
 *       public function __construct(protected Order $order) {}
 *
 *       protected function scope(FeedBuilder $feed): void
 *       {
 *           $feed->context($this->order);
 *       }
 *
 *       public function define(FeedBuilder $feed): void
 *       {
 *           $feed->only(['order.placed', 'order.delivered', 'order.paid'])->log();
 *       }
 *   }
 *
 *   CustomerFeed::make($order)->get();
 *
 * WHAT THIS ADDS OVER A CLOSURE PRESET. A closure can carry a query. It cannot
 * carry the one thing the original ask (a scope + allowlist seam "we cannot
 * forget at a call site") most needed: the SCOPE. With closures alone the call
 * site still reads `Storyfeed::feed('customer')->involving($order)`, and the
 * `involving($order)` is exactly the part that can be forgotten. Forgetting it
 * is not a visible failure — it is a customer-facing feed rendering every order
 * in the system, correctly verb-filtered and entirely plausible. The allowlist
 * half fails safe; the scope half fails open. That asymmetry is the whole
 * reason this class exists.
 *
 * THE ENTRY POINT IS A CONSTRUCTOR. `make()` is an alias for `new static(...)`,
 * so the subject is a typed constructor parameter and PHP itself refuses an
 * unscoped build: `CustomerFeed::make()` is an ArgumentCountError, not a feed.
 * Nothing here has to enforce that, which is the point — every prepositional
 * name considered instead (`for()`, `of()`, `scopedTo()`) claims a relationship
 * the class declares elsewhere, and `for()` in particular is the name this
 * package RETIRED at v0.7 for exactly that ambiguity. A constructor claims
 * nothing. (`new CustomerFeed()` is caught statically by PHPStan and the IDE.
 * `CustomerFeed::make()` forwards variadically, so PHP catches it at runtime on
 * the first call — and `PHPStan\FeedMakeArityRule`, which this package ships,
 * catches it in CI by resolving the call against the constructor it reaches.
 * The runtime guarantee is still the load-bearing one; the rule only moves
 * discovery earlier.)
 *
 * TWO HOOKS, BECAUSE TOOLING CANNOT CONSTRUCT YOU. `define()` declares what the
 * feed is ABOUT — verbs, mode, limits — and must not touch constructor state,
 * because `storyfeed:doctor` reads it without being able to supply a subject.
 * `scope()` binds the values only a request can supply. The split is what lets
 * a check see a customer feed's allowlist without inventing an order.
 *
 * NO MAGIC. `make()` and `build()` are ordinary methods; the only dynamic part
 * is `new static(...)`, which is how `PendingActivity::make()` already works.
 * No `__callStatic`, and a method this class does not declare is an error
 * rather than a feature.
 *
 * NOT AUTHORIZATION. Scope is a query constraint applied for you — identical to
 * the `->context($order)` you would otherwise write. It does not know who is
 * asking and it never hides an activity. A Feed declares what a surface is
 * ABOUT; a policy decides who may look at it. See docs/feeds.md.
 *
 * INERT UNLESS ENTERED. Unlike a Story, which records whether or not anyone
 * registered it, a Feed does nothing unless a surface enters through it —
 * `Storyfeed::feed()` still exists and is still unfiltered, as it must be. This
 * is a declaration you route through, not a wall around the table.
 */
abstract class Feed
{
    /**
     * Declare the feed: verbs, mode, limit — anything that does not depend on
     * what this instance was constructed with.
     *
     * Deliberately the SAME vocabulary a closure preset speaks: only(),
     * except(), log()/live()/summary(), query(), limit(). The class is a place,
     * not a dialect.
     *
     * MUST NOT read constructor state. `storyfeed:doctor` runs this against an
     * instance built WITHOUT calling the constructor, because it has no order
     * to hand you and still needs to know which verbs you named. Touching
     * `$this->order` here turns every doctor run into a `feeds.preset_failed`
     * finding for this feed. Bind values in scope() instead.
     */
    public function define(FeedBuilder $feed): void {}

    /**
     * Bind this feed's subject — the part only a request can supply.
     *
     *   protected function scope(FeedBuilder $feed): void
     *   {
     *       $feed->context($this->order);
     *   }
     *
     * The role is written in plain code rather than declared as a string, so
     * the query reads here the way it would read at a call site. Every role
     * bound in here is then LOCKED — see FeedBuilder::lockScope() for why scope
     * needs a lock when the verb allowlist does not.
     *
     * A feed with required constructor arguments that does not override this
     * throws (FeedMisconfigured::unscoped). That check lives in the base class
     * rather than in the generated stub on purpose: a guarantee you lose by
     * hand-writing the class instead of generating it would be worse than no
     * guarantee at all.
     */
    protected function scope(FeedBuilder $feed): void {}

    /**
     * The registry name, used when the class is registered without a key and
     * in doctor's findings. `CustomerFeed` → `customer`.
     */
    public static function name(): string
    {
        return FeedDefinition::deriveName(static::class);
    }

    /**
     * Construct and build in one call: `CustomerFeed::make($order)`.
     *
     * Variadic because a base class cannot know a subclass's signature; the
     * subclass's own constructor is what types and counts the arguments, which
     * is the whole guarantee.
     */
    public static function make(mixed ...$arguments): FeedBuilder
    {
        // Reflection rather than `new static(...)`: a subclass constructor
        // varies by design here — that IS the feature — so the shortcut static
        // analysers rightly call unsafe would be a claim this class cannot
        // make. The failure mode is unchanged: too few arguments is still an
        // ArgumentCountError, raised by PHP, on the first call.
        return (new ReflectionClass(static::class))->newInstance(...$arguments)->build();
    }

    /** This instance's builder — scoped, locked, and free to narrow further. */
    public function build(): FeedBuilder
    {
        return FeedDefinition::fromFeed($this)->build();
    }

    /**
     * @internal The binding hook, reachable by FeedDefinition — which owns the
     * lock and the unscoped check — without making scope() public API.
     */
    public function bindScope(FeedBuilder $feed): void
    {
        $this->scope($feed);
    }

    /**
     * A tombstone, in the same spirit as FeedBuilder::for().
     *
     * `for()` is the name people reach for first, and it is the one name this
     * API cannot have: it meant target when recording and involving when
     * reading, and on a class it would have meant whichever role the class
     * happens to bind — invisibly, at every call site. Saying so costs one
     * method and saves a reader the archaeology.
     */
    public static function for(Model|string $subject): never
    {
        throw new FeedMisconfigured(
            'Feed classes have no for(): the package retired that name at v0.7 because it meant '
            .'target when recording and involving when reading, and on a class it would have '
            .'meant whichever role the class binds, invisibly. Class feeds are entered with '
            .class_basename(static::class).'::make($subject) — the constructor types the subject, '
            .'and PHP refuses to build the feed without it.'
        );
    }
}
