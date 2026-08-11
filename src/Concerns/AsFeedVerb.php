<?php

namespace Storyfeed\Concerns;

use DateTimeInterface;
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

    public function activity(?Model $object = null): PendingActivity
    {
        return PendingActivity::make($this, $object);
    }

    public function record(
        ?Model $object = null,
        ?Model $actor = null,
        ?Model $target = null,
        ?Model $context = null,
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

    public function actor(?Model $model = null): PendingActivity
    {
        return $this->activity()->actor($model);
    }

    public function object(?Model $model = null): PendingActivity
    {
        return $this->activity()->object($model);
    }

    public function target(?Model $model = null): PendingActivity
    {
        return $this->activity()->target($model);
    }

    public function context(?Model $model = null): PendingActivity
    {
        return $this->activity()->context($model);
    }

    public function in(?Model $model = null): PendingActivity
    {
        return $this->activity()->in($model);
    }

    public function to(?Model $model = null): PendingActivity
    {
        return $this->activity()->to($model);
    }

    public function for(?Model $model = null): PendingActivity
    {
        return $this->activity()->for($model);
    }

    public function from(?Model $model = null): PendingActivity
    {
        return $this->activity()->from($model);
    }

    public function data(array $data): PendingActivity
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

    public function publish(?Model $object = null): Activity
    {
        return $this->activity($object)->publish();
    }

    public function publishAndReplace(?Model $object = null): Activity
    {
        return $this->activity($object)->publishAndReplace();
    }
}
