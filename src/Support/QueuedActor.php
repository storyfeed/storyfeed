<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Storyfeed\Models\Party;
use Storyfeed\StoryfeedManager;

/**
 * Scalar identity only: no model restoration and no identity in log context.
 *
 * Two kinds travel under one key. The auth user's identity is a default the
 * worker's application resolvers may override. A Storyfeed::as() actor is
 * marked `scoped`: the worker re-enters that scope for the job's duration,
 * as if the job had run inside the callback. "No actor" travels as no key at
 * all, never as a null actor, so an anonymous publish stays anonymous.
 */
class QueuedActor
{
    public const string KEY = 'storyfeed.internal.actor';

    public static function capture(Repository $context): void
    {
        // Dehydration receives a copy, so dispatch never changes request context.
        // A hidden null opts out, inside a Storyfeed::as() scope too.
        if ($context->hasHidden(self::KEY) && $context->getHidden(self::KEY) === null) {
            return;
        }

        // The innermost as() scope wins over an inherited identity and auth.
        // An empty identity is a scope with nothing to carry: send nothing.
        $scoped = app(StoryfeedManager::class)->scopedActor();
        if ($scoped !== null) {
            if ($scoped !== []) {
                $context->addHidden(self::KEY, $scoped);
            }

            return;
        }

        // Preserve inherited identity for chained jobs.
        if ($context->hasHidden(self::KEY)) {
            return;
        }

        $actor = Auth::user();
        if ($actor instanceof Model && $actor->getKey() !== null) {
            $context->addHidden(self::KEY, [
                'type' => $actor->getMorphClass(),
                'id' => $actor->getKey(),
            ]);
        }
    }

    /**
     * A Storyfeed::as() actor as a scoped identity: a Party by name and key
     * (it may be unsaved while recording is off), a model by morph alias and
     * key. Empty when a model has no key yet, which nothing could restore.
     *
     * @return array<string, mixed>
     */
    public static function identify(Model $actor): array
    {
        if ($actor instanceof Party) {
            return ['party' => $actor->name, 'key' => $actor->key, 'scoped' => true];
        }

        if ($actor->getKey() === null) {
            return [];
        }

        return ['type' => $actor->getMorphClass(), 'id' => $actor->getKey(), 'scoped' => true];
    }

    /**
     * Whether a transported value is a well-formed scoped identity.
     *
     * @phpstan-assert-if-true array<string, mixed> $identity
     */
    public static function isScoped(mixed $identity): bool
    {
        if (! is_array($identity) || ($identity['scoped'] ?? null) !== true) {
            return false;
        }

        if (array_key_exists('party', $identity)) {
            return is_string($identity['party']) && is_string($identity['key'] ?? null);
        }

        return is_string($identity['type'] ?? null)
            && (is_int($identity['id'] ?? null) || is_string($identity['id'] ?? null));
    }

    /**
     * Re-enter a scoped actor for the job's duration. Read from the payload
     * rather than the hydrated context, so listener order doesn't matter.
     */
    public static function enter(JobProcessing $event): void
    {
        $value = $event->job->payload()['illuminate:log:context']['hidden'][self::KEY] ?? null;
        $identity = is_string($value) ? unserialize($value, ['allowed_classes' => false]) : null;

        if (self::isScoped($identity)) {
            app(StoryfeedManager::class)->enterQueuedScope(spl_object_id($event->job), $identity);
        }
    }

    /**
     * Put back whatever the job's scope replaced. Idempotent: a job that
     * throws on the worker raises both JobExceptionOccurred and JobAttempted.
     */
    public static function leave(JobProcessed|JobExceptionOccurred|JobAttempted $event): void
    {
        app(StoryfeedManager::class)->leaveQueuedScope(spl_object_id($event->job));

        // A sync job hydrates its payload over the request's context and
        // Laravel leaves it there. The request never held a scoped identity
        // (dispatch writes to a copy), so forgetting it puts the request back.
        $context = app(Repository::class);
        if (self::isScoped($context->getHidden(self::KEY))) {
            $context->forgetHidden(self::KEY);
        }
    }
}
