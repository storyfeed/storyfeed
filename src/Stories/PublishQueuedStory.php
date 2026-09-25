<?php

namespace Storyfeed\Stories;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use LogicException;
use ReflectionClass;
use Storyfeed\PublishQueuedActivity;
use Throwable;

/**
 * The job a queued message class rides in, as a queued Notification rides
 * in `SendQueuedNotifications` and a queued listener in `CallQueuedListener`:
 * `Storyfeed::publish(new OrderConfirmed($order))` makes one when the class
 * implements `ShouldQueue`, and the worker calls its toFeedActivity() and
 * publishes what it returns.
 *
 * FROM THE CLASS, as `Events\Dispatcher::propagateListenerOptions()` takes
 * a queued listener's: where it goes (`$connection`, `$queue`, `$delay`,
 * `$afterCommit` or `ShouldQueueAfterCommit`, over what the line in
 * routes/feed.php declared), its job middleware (`through()`), its chain,
 * `$tries`, `$timeout`, `$maxExceptions`, `$failOnTimeout`, `backoff()`,
 * `retryUntil()`, `ShouldBeEncrypted` and `$deleteWhenMissingModels`, and
 * then:
 *
 * UNIQUENESS. `ShouldBeUnique`, `ShouldBeUniqueUntilProcessing`,
 * `uniqueId()` and `uniqueFor`, copied as the listener's are, with the lock
 * keyed on the class. While one publish is pending, a second is dropped:
 * the first wins, and nothing is written for the second. Laravel honours a
 * listener's flags by knowing CallQueuedListener by name, so this job takes
 * and releases its lock itself, where the queue handler would.
 *
 * DEBOUNCING. Story classes cannot declare `#[DebounceFor]` or
 * `$debounceFor`: Laravel does not forward these from queued mailables,
 * notifications or listeners. The publisher rejects them at the call site;
 * declare `keepLatest(within:)` on the verb to keep the latest stored row.
 *
 * `published_at` is stamped at the dispatch, and missing models fail or
 * drop the job as PublishQueuedActivity's do, on every supported Laravel.
 *
 * @internal Made by StoryfeedManager::publish().
 */
class PublishQueuedStory implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** @var class-string<Story> */
    public string $class;

    /** When `Storyfeed::publish()` was called: the activity's time, unless it says another. */
    public Carbon $publishedAt;

    public bool $deleteWhenMissingModels = false;

    public mixed $tries = null;

    public mixed $timeout = null;

    public mixed $maxExceptions = null;

    public bool $failOnTimeout = false;

    public mixed $backoff = null;

    public mixed $retryUntil = null;

    public bool $shouldBeEncrypted = false;

    public bool $shouldBeUnique = false;

    public bool $shouldBeUniqueUntilProcessing = false;

    public mixed $uniqueId = null;

    public ?int $uniqueFor = null;

    /** The message, or on the worker until handle() restores it, its serialized form. */
    private Story|string $story;

    /**
     * @param  array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}  $declared  what routes/feed.php says
     */
    public function __construct(Story $story, array $declared = [])
    {
        $this->story = $story;
        $this->class = $story::class;
        $this->publishedAt = now();

        $this->connection = self::declared($story, 'connection', 'Connection') ?? $declared['connection'] ?? null;
        $this->queue = self::declared($story, 'queue', 'Queue') ?? $declared['queue'] ?? null;
        $this->delay = self::declared($story, 'delay', 'Delay') ?? $declared['delay'] ?? null;
        $this->afterCommit = $story instanceof ShouldQueueAfterCommit ? true : (self::declared($story, 'afterCommit') ?? $declared['afterCommit'] ?? null);
        $this->deleteWhenMissingModels = (bool) (self::declared($story, 'deleteWhenMissingModels', 'DeleteWhenMissingModels') ?? $declared['deleteWhenMissingModels'] ?? false);

        // Queueable's properties, when the class uses it. `$middleware` is
        // the job middleware `through()` sets; the class's middleware()
        // method is its story middleware, which runs inside publish().
        foreach (['middleware', 'chained', 'chainConnection', 'chainQueue', 'chainCatchCallbacks'] as $property) {
            if (isset($story->{$property})) {
                $this->{$property} = $story->{$property};
            }
        }

        $this->tries = method_exists($story, 'tries') ? $story->tries() : self::declared($story, 'tries', 'Tries');
        $this->timeout = self::declared($story, 'timeout', 'Timeout');
        $this->maxExceptions = self::declared($story, 'maxExceptions', 'MaxExceptions');
        $this->failOnTimeout = (bool) self::declared($story, 'failOnTimeout', 'FailOnTimeout');
        $this->backoff = method_exists($story, 'backoff') ? $story->backoff() : self::declared($story, 'backoff', 'Backoff');
        $this->retryUntil = method_exists($story, 'retryUntil') ? $story->retryUntil() : self::declared($story, 'retryUntil');
        $this->shouldBeEncrypted = $story instanceof ShouldBeEncrypted;

        $this->shouldBeUnique = $story instanceof ShouldBeUnique;
        $this->shouldBeUniqueUntilProcessing = $story instanceof ShouldBeUniqueUntilProcessing;

        if ($this->shouldBeUnique) {
            $this->uniqueId = method_exists($story, 'uniqueId') ? $story->uniqueId() : self::declared($story, 'uniqueId');
            $this->uniqueFor = (int) (method_exists($story, 'uniqueFor') ? $story->uniqueFor() : (self::declared($story, 'uniqueFor', 'UniqueFor') ?? 0));
        }
    }

    /** Reject unsupported declarations before dispatch, including on the fake. */
    public static function ensureNotDebounced(Story $story): void
    {
        if (property_exists($story, 'debounceFor') || self::attribute($story, 'DebounceFor') !== null) {
            throw new LogicException(
                '['.$story::class."] DebounceFor isn't supported on Story classes, as it isn't on queued mailables, notifications or listeners. Declare ->keepLatest(within: '…') on the verb instead.",
            );
        }
    }

    /**
     * Queue a message: dropped when it is unique and one is pending, as a
     * queued listener is.
     *
     * @param  array{connection?: string, queue?: string, delay?: int, afterCommit?: bool, deleteWhenMissingModels?: bool}  $declared
     */
    public static function dispatchFor(Story $story, array $declared = []): void
    {
        $job = new self($story, $declared);

        if ($job->shouldBeUnique && ! (new UniqueLock(app(Cache::class)))->acquire($job)) {
            return;
        }

        dispatch($job);
    }

    public function handle(): void
    {
        if ($this->shouldBeUniqueUntilProcessing) {
            $this->releaseUniqueLock();
        }

        try {
            $story = $this->story();
        } catch (ModelNotFoundException $e) {
            $this->releaseUniqueLock();

            PublishQueuedActivity::missingModels($this, $e);

            return;
        }

        $activity = $story->toFeedActivity();

        if ($activity !== null) {
            if ($activity->activity->published_at === null) {
                $activity->publishedAt($this->publishedAt);
            }

            // The queue waited for the commit, if it was asked to.
            $activity->beforeCommit()->publish();
        }

        $this->releaseUniqueLock();
    }

    /** As `SendQueuedNotifications::failed()` hands the notification its failure. */
    public function failed(Throwable $e): void
    {
        $this->releaseUniqueLock();

        if (! method_exists($this->class, 'failed')) {
            return;
        }

        try {
            $story = $this->story();
        } catch (ModelNotFoundException) {
            return; // Its models are what failed.
        }

        if (method_exists($story, 'failed')) {
            $story->failed($e);
        }
    }

    /**
     * The message this job publishes.
     *
     * @throws ModelNotFoundException a model it names is gone
     */
    public function story(): Story
    {
        if (is_string($this->story)) {
            $restored = unserialize($this->story);

            assert($restored instanceof Story);

            $this->story = $restored;
        }

        return $this->story;
    }

    /** The class, as a queued listener's job is named for its listener: in Horizon, and in unique locks. */
    public function displayName(): string
    {
        return $this->class;
    }

    public function uniqueVia(): ?Cache
    {
        if (! method_exists($this->class, 'uniqueVia')) {
            return null;
        }

        $story = $this->story();

        return method_exists($story, 'uniqueVia') ? $story->uniqueVia() : null;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $values = get_object_vars($this);

        $values['story'] = is_string($this->story) ? $this->story : serialize($this->story);

        return $values;
    }

    /** @param  array<string, mixed>  $values */
    public function __unserialize(array $values): void
    {
        foreach ($values as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /** What the queue handler releases for a ShouldBeUnique job: once handled, or on failure. */
    private function releaseUniqueLock(): void
    {
        if ($this->shouldBeUnique) {
            (new UniqueLock(app(Cache::class)))->release($this);
        }
    }

    /**
     * A setting from the class's property, else its queue attribute
     * (`#[Tries(3)]`), as `ReadsClassAttributes::getAttributeValue()` reads
     * a job's; an attribute this Laravel doesn't have is never there.
     */
    private static function declared(Story $story, string $property, ?string $attribute = null): mixed
    {
        if (isset($story->{$property})) {
            return $story->{$property};
        }

        if ($attribute === null || ($instance = self::attribute($story, $attribute)) === null) {
            return null;
        }

        $values = get_object_vars($instance);

        return $values === [] ? true : reset($values);
    }

    private static function attribute(Story $story, string $name): ?object
    {
        $attribute = 'Illuminate\\Queue\\Attributes\\'.$name;

        if (! class_exists($attribute)) {
            return null;
        }

        for ($class = new ReflectionClass($story); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes($attribute) as $found) {
                return $found->newInstance();
            }
        }

        return null;
    }
}
