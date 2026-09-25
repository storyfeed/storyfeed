<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Storyfeed\Facades\Storyfeed;

class ScopedContextJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $input */
    public function __construct(public array $input = []) {}

    public function handle(): void
    {
        $publish = function () {
            $pending = Storyfeed::activity($this->input['verb'] ?? 'probe');
            if (isset($this->input['context'])) {
                $pending->context($this->input['context']);
            }
            $pending->publish();
        };
        isset($this->input['scope']) ? Storyfeed::context($this->input['scope'], $publish) : $publish();
        if (isset($this->input['child'])) {
            self::dispatch($this->input['child']);
        }
        if ($this->input['throw'] ?? false) {
            throw new RuntimeException('job failed');
        }
    }
}
