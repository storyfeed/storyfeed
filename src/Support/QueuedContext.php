<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Storyfeed\StoryfeedManager;

/** Hidden scalar identity, following QueuedActor's dispatch and worker lifecycle. */
class QueuedContext
{
    public const string KEY = 'storyfeed.internal.context';

    /** @return array<string, mixed> */
    public static function identify(Model $model): array
    {
        $identity = QueuedActor::identify($model);
        unset($identity['scoped']);

        return $identity;
    }

    public static function capture(Repository $context): void
    {
        if ($context->hasHidden(self::KEY) && $context->getHidden(self::KEY) === null) {
            return;
        }

        $identity = app(StoryfeedManager::class)->scopedContext();
        if ($identity !== null) {
            // An unsaved inner model must not inherit an outer job's identity.
            $context->forgetHidden(self::KEY);
            if ($identity !== []) {
                $context->addHidden(self::KEY, $identity);
            }
        }
    }

    public static function enter(JobProcessing $event): void
    {
        $value = $event->job->payload()['illuminate:log:context']['hidden'][self::KEY] ?? null;
        $identity = is_string($value) ? unserialize($value, ['allowed_classes' => false]) : null;

        if (is_array($identity) && QueuedActor::isScoped([...$identity, 'scoped' => true])) {
            app(StoryfeedManager::class)->enterQueuedContext(spl_object_id($event->job), $identity);
        }
    }

    public static function leave(JobProcessed|JobExceptionOccurred|JobAttempted $event): void
    {
        app(StoryfeedManager::class)->leaveQueuedContext(spl_object_id($event->job));
        app(Repository::class)->forgetHidden(self::KEY);
    }
}
