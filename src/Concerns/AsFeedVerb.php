<?php

namespace Storyfeed\Concerns;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\FeedThread;
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
     */
    public function of(Model|string|null $object = null): PendingActivity
    {
        return PendingActivity::make($this, $object);
    }

    /** Begin this verb's activity with an explicitly unknown actor. */
    public function anonymous(Model|string|null $object = null): PendingActivity
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
     * and `thread` before `origin`/`result`/`instrument`. This trait shipped
     * the three AS2 roles first and the two older parameters second, so they
     * could only be appended: reordering to match would silently change what
     * every existing positional argument means. Named arguments make the
     * difference invisible in practice, which is exactly why it needs saying
     * here — the divergence is the compatible choice, not an oversight, and
     * "tidying" it is a breaking change wearing a refactor's clothes.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<int, Model>  $objects
     */
    public function record(
        Model|string|null $object = null,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        Model|string|null $context = null,
        array $data = [],
        DateTimeInterface|string|null $publishedAt = null,
        Model|string|null $origin = null,
        Model|string|null $result = null,
        Model|string|null $instrument = null,
        iterable $objects = [],
        ?FeedThread $thread = null,
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
            thread: $thread,
        );
    }

    // ── Forwarded chainables ─────────────────────────────────────────────

    public function anonymously(): PendingActivity
    {
        return $this->of()->anonymously();
    }

    public function actor(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->actor($model);
    }

    public function by(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->by($model);
    }

    public function object(Model|string|null $model = null): PendingActivity
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

    public function target(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->target($model);
    }

    public function origin(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->origin($model);
    }

    public function result(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->result($model);
    }

    public function instrument(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->instrument($model);
    }

    public function using(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->using($model);
    }

    public function resulting(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->resulting($model);
    }

    public function context(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->context($model);
    }

    public function in(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->in($model);
    }

    public function to(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->to($model);
    }

    public function for(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->for($model);
    }

    public function from(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->from($model);
    }

    public function on(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->on($model);
    }

    public function with(Model|string|null $model = null): PendingActivity
    {
        return $this->of()->with($model);
    }

    public function into(Model|string|null $model = null): PendingActivity
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

    public function thread(FeedThread $thread): PendingActivity
    {
        return $this->of()->thread($thread);
    }

    public function publishedAt(DateTimeInterface|string $date): PendingActivity
    {
        return $this->of()->publishedAt($date);
    }

    // ── Terminals ────────────────────────────────────────────────────────

    public function publish(Model|string|null $object = null): Activity
    {
        return $this->of($object)->publish();
    }
}
