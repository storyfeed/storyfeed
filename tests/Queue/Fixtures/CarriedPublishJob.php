<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

/** Consumer-shaped queued job: publishes a verb on a delivery, optionally dispatches another. */
class CarriedPublishJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>  $input */
    public function __construct(public int $delivery, public string $verb = 'refund', public array $input = []) {}

    public function handle(): void
    {
        $pending = Storyfeed::activity($this->verb, Delivery::findOrFail($this->delivery));

        if (isset($this->input['actor'])) {
            $pending->actor($this->input['actor']);
        }

        if ($this->input['anonymous'] ?? false) {
            $pending->anonymously();
        }

        $pending->publish();

        if (isset($this->input['child'])) {
            self::dispatch($this->delivery, $this->input['child']);
        }
    }
}
