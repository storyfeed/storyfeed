<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Carbon;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Has anything been published lately?
 *
 * The dumbest check here, and the one most likely to earn its place. The
 * failure this package keeps hearing about is not a broken feed — it is a feed
 * that quietly stops keeping up with the app: the grammar gets set once, new
 * modules ship, and nothing publishes from them. Every other check answers
 * "is what we have correct?"; only this one answers "is there still anything
 * arriving?"
 *
 * Honest about its limits: a module that never touches Storyfeed at all is
 * invisible to Storyfeed, and no check inside the package can see it. This is
 * the closest available proxy, not a proof. `surface.unwired` covers the part
 * that IS detectable — declared feed surface that publishes nothing.
 *
 * Configure with `storyfeed.doctor.stale_after` (days; null disables).
 */
class FeedStale extends Check
{
    public function name(): string
    {
        return 'freshness';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $days = config('storyfeed.doctor.stale_after', 30);

        if ($days === null || ! $this->hasTable('activities')) {
            return;
        }

        $latest = $this->activities()->max('published_at');

        if ($latest === null) {
            yield Finding::info(
                'freshness.empty',
                'No activities have ever been published — nothing to assess yet.',
            );

            return;
        }

        $since = Carbon::parse($latest);
        $elapsed = (int) $since->diffInDays(Carbon::now());

        if ($elapsed >= (int) $days) {
            yield Finding::warning(
                'freshness.stale',
                "Nothing has been published to the feed in {$elapsed} days (since {$since->toDateString()}). "
                .'Either the feed has stopped being written to, or the app grew past what it publishes — '
                .'run storyfeed:stories to see which surface is unwired.',
                ['days' => $elapsed, 'latest' => $since->toIso8601String()],
            );
        }
    }
}
