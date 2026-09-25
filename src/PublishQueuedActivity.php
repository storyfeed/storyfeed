<?php

namespace Storyfeed;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The job a queued PendingActivity rides in, as a queued Mailable rides in
 * `SendQueuedMailable`: `->queue()` makes one, and the worker publishes it.
 *
 * It takes where it goes from the PendingActivity (the Queueable
 * properties the call site set over the verb's declaration), and carries
 * the activity by its models' identifiers, never whole models.
 *
 * MISSING MODELS behave as Laravel's do: a model deleted since the dispatch
 * fails the job into `failed_jobs`, or, with `deleteWhenMissingModels`,
 * drops it silently along with its chain. Laravel decides which while it
 * unserializes a job, from the payload on Laravel 13 but from the job
 * class's default on Laravel 12, where one class can't say both. So the
 * activity is serialized on its own and restored in handle(), where this
 * job decides the same way on every supported Laravel.
 *
 * @internal Made by PendingActivity::queue().
 */
class PublishQueuedActivity implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public bool $deleteWhenMissingModels = false;

    /** The activity, or on the worker until handle() restores it, its serialized form. */
    private PendingActivity|string $activity;

    public function __construct(PendingActivity $activity)
    {
        $this->activity = $activity;

        $this->connection = $activity->connection;
        $this->queue = $activity->queue;
        $this->delay = $activity->delay;
        $this->afterCommit = $activity->afterCommit;
        $this->middleware = $activity->middleware;
        $this->chained = $activity->chained;
        $this->chainConnection = $activity->chainConnection;
        $this->chainQueue = $activity->chainQueue;
        $this->chainCatchCallbacks = $activity->chainCatchCallbacks;
        $this->deleteWhenMissingModels = $activity->deleteWhenMissingModels ?? false;
    }

    public function handle(): void
    {
        try {
            $activity = $this->activity();
        } catch (ModelNotFoundException $e) {
            self::missingModels($this, $e);

            return;
        }

        // The queue waited for the commit, if it was asked to; the worker
        // publishes now.
        $activity->beforeCommit()->publish();
    }

    /**
     * The PendingActivity this job publishes.
     *
     * @throws ModelNotFoundException a model it names is gone
     */
    public function activity(): PendingActivity
    {
        if (is_string($this->activity)) {
            $restored = unserialize($this->activity);

            assert($restored instanceof PendingActivity);

            $this->activity = $restored;
        }

        return $this->activity;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $values = get_object_vars($this);

        $values['activity'] = is_string($this->activity) ? $this->activity : serialize($this->activity);

        return $values;
    }

    /** @param  array<string, mixed>  $values */
    public function __unserialize(array $values): void
    {
        foreach ($values as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * What `CallQueuedHandler::handleModelNotFound()` does: delete the job
     * without running its chain, or fail it.
     *
     * @param  PublishQueuedActivity|Stories\PublishQueuedStory  $job
     * @param  ModelNotFoundException<Model>  $e
     *
     * @internal
     */
    public static function missingModels(object $job, ModelNotFoundException $e): void
    {
        if ($job->deleteWhenMissingModels) {
            $job->chained = [];
            $job->delete();

            return;
        }

        $job->fail($e);
    }
}
