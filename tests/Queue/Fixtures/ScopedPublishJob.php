<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Storyfeed\Facades\Storyfeed;

/** Consumer-shaped queued job: publishes, optionally throws, optionally dispatches another. */
class ScopedPublishJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>  $input */
    public function __construct(public array $input = []) {}

    public function handle(): void
    {
        $publish = function () {
            $pending = Storyfeed::activity($this->input['verb'] ?? 'queue-probe');
            if (isset($this->input['actor'])) {
                $pending->actor($this->input['actor']);
            }
            if ($this->input['anonymous'] ?? false) {
                $pending->anonymously();
            }
            $pending->publish();
        };

        isset($this->input['as']) ? Storyfeed::as($this->input['as'], $publish) : $publish();

        if (isset($this->input['child'])) {
            self::dispatch($this->input['child']);
        }

        if ($this->input['throw'] ?? false) {
            throw new RuntimeException('job failed');
        }
    }
}
