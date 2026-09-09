<?php

namespace Storyfeed\Healing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SyncToken;

/**
 * Permanent-source retirement only. Absence of an activity is never an
 * instruction: pruning may have erased evidence of a deliberate removal.
 *
 * Writes soft-delete live rows and bump sync_token in the same transaction.
 * Accumulating clients must discard their old pages and resync. Prefer a quiet
 * period; preview with dryRun first. This service is never scheduled by core.
 */
final class HealFeed
{
    public function __construct(private readonly StoryfeedManager $manager) {}

    /**
     * Stream results, keeping memory bounded. Consume the iterable to run it.
     * The app's candidates() and whenAbsent callbacks must be read-only.
     *
     * @param  list<string>|null  $only
     * @return iterable<HealResult>
     */
    public function run(bool $dryRun = false, ?array $only = null): iterable
    {
        $healers = $this->manager->registeredHealers();

        foreach ($only ?? [] as $key) {
            if (! array_key_exists($key, $healers)) {
                throw new InvalidArgumentException("Unknown healer [{$key}].");
            }
        }

        foreach ($healers as $key => $healer) {
            // PHP stores numeric string array keys as integers.
            $key = (string) $key;

            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            foreach ($healer->candidates() as $candidate) {
                yield new HealResult($key, $candidate, $this->retire($candidate, $dryRun));
            }
        }
    }

    private function retire(StoryRetirement $candidate, bool $dryRun): HealOutcome
    {
        $reconcile = function () use ($candidate, $dryRun): HealOutcome {
            $model = config('storyfeed.models.activity', Activity::class);
            $query = $model::query()->whereKey($candidate->activityId);

            if ($query->getConnection() !== DB::connection()) {
                throw new InvalidArgumentException('Healing activities must use the default database connection so retirement and sync_token commit together.');
            }

            if (! $dryRun) {
                $query->lockForUpdate();
            }

            // Re-read by physical row identity, never trust the row seen while
            // enumerating candidates. A deleted or pruned row stays absent.
            $live = $query->first();

            if ($live === null || ! ($candidate->whenAbsent)($live)) {
                return HealOutcome::Unchanged;
            }

            if (! $dryRun) {
                if (! $live->delete()) {
                    return HealOutcome::Unchanged;
                }

                SyncParticipants::forget($live->getKey());
                SyncToken::bump();
            }

            return HealOutcome::Retired;
        };

        // Preview performs reads only, with no locks, metadata or run records.
        return $dryRun ? $reconcile() : DB::transaction($reconcile);
    }
}
