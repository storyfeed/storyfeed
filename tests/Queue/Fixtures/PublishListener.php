<?php

namespace Storyfeed\Tests\Queue\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

/** Consumer-shaped queued listener, not package queue support. */
class PublishListener implements ShouldQueue
{
    public static array $seen = [];

    public static array $rendered = [];

    public function handle(array $input): void
    {
        $pending = Storyfeed::activity('queue-probe', Delivery::findOrFail($input['delivery']));
        if (isset($input['target'])) {
            $pending->for(PlainTarget::findOrFail($input['target']));
        }
        if (isset($input['actor'])) {
            $pending->actor($input['actor']);
        }
        if ($input['anonymous'] ?? false) {
            $pending->anonymously();
        }
        if (isset($input['occurred_at'])) {
            $pending->publishedAt($input['occurred_at']);
        }
        $activity = $pending->publish();
        self::$seen[] = ['auth' => Auth::id(), 'id' => $activity->id];
        if ($input['render'] ?? false) {
            self::$rendered = Storyfeed::feed()->get()->toArray();
        }
    }
}
