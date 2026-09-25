<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Throwable;
use Workbench\App\Models\Delivery;

/** A message class that queues: a queued Notification's shape. */
class QueuedDispatch extends Story implements ShouldQueue
{
    use Queueable;

    public string|array|null $objectType = Delivery::class;

    /** @var list<string> */
    public static array $failures = [];

    public static int $built = 0;

    public static bool $throws = false;

    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        self::$built++;

        if (self::$throws) {
            throw new RuntimeException('The worker failed.');
        }

        return $this->activity($this->delivery);
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }

    public function failed(Throwable $e): void
    {
        self::$failures[] = $e::class;
    }
}
