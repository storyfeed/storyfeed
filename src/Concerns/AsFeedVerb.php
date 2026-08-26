<?php

namespace Storyfeed\Concerns;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;

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

    public function activity(Model|string|null $object = null): PendingActivity
    {
        return PendingActivity::make($this, $object);
    }

    public function record(
        Model|string|null $object = null,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        Model|string|null $context = null,
        array $data = [],
        DateTimeInterface|string|null $publishedAt = null,
        bool $replace = false,
    ): Activity {
        return storyfeed()->record(
            verb: $this,
            object: $object,
            actor: $actor,
            target: $target,
            context: $context,
            data: $data,
            publishedAt: $publishedAt,
            replace: $replace,
        );
    }

    // ── Forwarded chainables ─────────────────────────────────────────────

    public function actor(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->actor($model);
    }

    /**
     * On an enum the verb is already known, so this only takes the object —
     * `ActivityVerb::Upload->action($document)` is `->activity($document)` with
     * the sentence's word.
     */
    public function action(Model|string|null $object = null): PendingActivity
    {
        return $this->activity($object);
    }

    public function by(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->by($model);
    }

    public function object(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->object($model);
    }

    /**
     * A composite: one story whose object is a collection of models.
     *
     * @param  iterable<int, Model>  $models
     */
    public function objects(iterable $models): PendingActivity
    {
        return $this->activity()->objects($models);
    }

    public function target(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->target($model);
    }

    public function context(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->context($model);
    }

    public function in(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->in($model);
    }

    public function to(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->to($model);
    }

    public function for(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->for($model);
    }

    public function from(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->from($model);
    }

    public function on(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->on($model);
    }

    public function with(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->with($model);
    }

    public function into(Model|string|null $model = null): PendingActivity
    {
        return $this->activity()->into($model);
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public function data(array|Arrayable $data): PendingActivity
    {
        return $this->activity()->data($data);
    }

    public function publishedAt(DateTimeInterface|string $date): PendingActivity
    {
        return $this->activity()->publishedAt($date);
    }

    public function replace(bool $replace = true): PendingActivity
    {
        return $this->activity()->replace($replace);
    }

    // ── Terminals ────────────────────────────────────────────────────────

    public function publish(Model|string|null $object = null): Activity
    {
        return $this->activity($object)->publish();
    }

    public function publishAndReplace(Model|string|null $object = null): Activity
    {
        return $this->activity($object)->publishAndReplace();
    }
}
