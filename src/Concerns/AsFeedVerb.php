<?php

namespace Storyfeed\Concerns;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\StoryfeedManager;

/**
 * Turns a backed string enum into a feed verb that can start a recording.
 *
 *   enum ActivityVerb: string implements FeedVerb
 *   {
 *       use AsFeedVerb;
 *
 *       case Comment = 'comment';
 *   }
 *
 *   ActivityVerb::Comment->actor($user)->object($comment)->in($project)->publish();
 *   ActivityVerb::Confirm->of($delivery)->by($user)->publish();
 *   ActivityVerb::Confirm->publish($delivery);
 *
 * Requires a backed enum — `$this->value` is the stored verb.
 *
 * Every chainable method here forwards to PendingActivity; a parity test
 * asserts none is missing.
 *
 * @mixin \BackedEnum
 */
trait AsFeedVerb
{
    // ── FeedVerb contract ────────────────────────────────────────────────

    public function verb(): string
    {
        return $this->value;
    }

    /**
     * Override to declare the AS2.0 mapping. Null means "no opinion" — the
     * verb registry resolves it.
     */
    public function activityType(): ActivityType|string|null
    {
        return null;
    }

    // ── Entry points ─────────────────────────────────────────────────────

    /**
     * Begin this verb's activity, about this object:
     * `ActivityVerb::Upload->of($document)->by($user)->publish()`.
     *
     * @param  Model|string|array<string, mixed>|null  $object
     */
    public function of(Model|string|array|null $object = null): PendingActivity
    {
        return PendingActivity::make($this, $object);
    }

    /**
     * Begin this verb's activity with an explicitly unknown actor.
     *
     * @param  Model|string|array<string, mixed>|null  $object
     */
    public function anonymous(Model|string|array|null $object = null): PendingActivity
    {
        return $this->of($object)->anonymously();
    }

    /**
     * The third recording surface, and the one that keeps being found late —
     * twice by accident before W61 went looking and established there were
     * three rather than two.
     *
     * **The parameter ORDER here deliberately differs from
     * {@see StoryfeedManager::record()}**, which takes `objects`
     * before `origin`/`result`/`instrument`. This trait shipped
     * the three AS2 roles first and `objects` second, so it
     * could only be appended: reordering to match would silently change what
     * every existing positional argument means. Named arguments make the
     * difference invisible in practice, which is exactly why it needs saying
     * here — the divergence is the compatible choice, not an oversight, and
     * "tidying" it is a breaking change wearing a refactor's clothes.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<int, Model>  $objects
     * @param  Model|string|array<string, mixed>|null  $object
     * @param  Model|string|array<string, mixed>|null  $actor
     * @param  Model|string|array<string, mixed>|null  $target
     * @param  Model|string|array<string, mixed>|null  $context
     * @param  Model|string|array<string, mixed>|null  $origin
     * @param  Model|string|array<string, mixed>|null  $result
     * @param  Model|string|array<string, mixed>|null  $instrument
     * @param  Model|string|array<string, mixed>|null  $location
     * @param  Model|string|array<string, mixed>|null  $generator
     */
    public function record(
        Model|string|array|null $object = null,
        Model|string|array|null $actor = null,
        Model|string|array|null $target = null,
        Model|string|array|null $context = null,
        array $data = [],
        DateTimeInterface|string|null $publishedAt = null,
        Model|string|array|null $origin = null,
        Model|string|array|null $result = null,
        Model|string|array|null $instrument = null,
        iterable $objects = [],
        DateTimeInterface|string|null $startsAt = null,
        DateTimeInterface|string|null $endsAt = null,
        Model|string|array|null $location = null,
        Model|string|array|null $generator = null,
    ): Activity {
        return storyfeed()->record(
            verb: $this,
            object: $object,
            actor: $actor,
            target: $target,
            context: $context,
            data: $data,
            publishedAt: $publishedAt,
            origin: $origin,
            result: $result,
            instrument: $instrument,
            objects: $objects,
            startsAt: $startsAt,
            endsAt: $endsAt,
            location: $location,
            generator: $generator,
        );
    }

    // ── Forwarded chainables ─────────────────────────────────────────────

    public function anonymously(): PendingActivity
    {
        return $this->of()->anonymously();
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function actor(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->actor($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function by(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->by($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function object(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->object($model);
    }

    /**
     * A composite: one story whose object is a collection of models.
     *
     * @param  iterable<int, Model>  $models
     */
    public function objects(iterable $models): PendingActivity
    {
        return $this->of()->objects($models);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function target(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function origin(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->origin($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function result(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->result($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function instrument(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->instrument($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function location(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->location($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function at(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->at($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function generator(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->generator($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function using(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->using($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function resulting(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->resulting($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function context(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->context($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function in(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->in($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function to(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->to($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function for(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->for($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function from(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->from($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function on(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->on($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function with(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->with($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function into(Model|string|array|null $model = null): PendingActivity
    {
        return $this->of()->into($model);
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public function data(array|Arrayable $data): PendingActivity
    {
        return $this->of()->data($data);
    }

    public function publishedAt(DateTimeInterface|string $date): PendingActivity
    {
        return $this->of()->publishedAt($date);
    }

    public function startsAt(DateTimeInterface|string $date): PendingActivity
    {
        return $this->of()->startsAt($date);
    }

    public function endsAt(DateTimeInterface|string $date): PendingActivity
    {
        return $this->of()->endsAt($date);
    }

    // ── Terminals ────────────────────────────────────────────────────────

    /**
     * @param  Model|string|array<string, mixed>|null  $object
     */
    public function publish(Model|string|array|null $object = null): Activity
    {
        return $this->of($object)->publish();
    }
}
