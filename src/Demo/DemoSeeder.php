<?php

namespace Storyfeed\Demo;

use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Healing\Removals;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\SyncToken;

/**
 * Publishes a screenplay, and removes what it published.
 *
 * Everything here goes through the ordinary write path — `Storyfeed::activity()`
 * with resolved Party models in each role — so a seeded demo exercises party
 * resolution, snapshotting, grouping and inline curation exactly as production
 * does. A demo that took a shortcut past that would be showing the audience code
 * nobody runs, and would stop catching regressions the moment it diverged.
 *
 * Teardown is the half that has to be trustworthy. It matches on the `demo.`
 * verb prefix and nothing else: no truncation, no JSON path expression, no
 * "delete everything published before X". The worst case for a mistake here is
 * an application losing real activities to a demo command, so the query is one a
 * reader can check by eye.
 */
class DemoSeeder
{
    public function __construct(
        private readonly Cast $cast,
        private readonly Screenplay $screenplay,
    ) {}

    /**
     * Publish the screenplay plus one cache-loss example when non-empty.
     * Returns the activity count, which depends on the seed and number of days.
     */
    public function seed(): int
    {
        Vocabulary::register();

        $published = 0;

        foreach ($this->screenplay->beats() as $beat) {
            $activity = Storyfeed::activity($beat->verb)
                ->publishedAt($beat->publishedAt)
                ->data([...$beat->data, 'demo' => true]);

            // Roles are resolved to typed Party rows rather than passed as bare
            // strings: a string would resolve through the manager's default
            // `Party::make($name)`, giving every entity the `Service` type and an
            // unprefixed key — losing both the AS2.0 typing the demo wants to
            // show and the `demo-` key that makes the row identifiable.
            if ($beat->actor !== null) {
                $activity->actor($this->cast->party($beat->actor));
            }

            if ($beat->object !== null) {
                $activity->object($this->cast->party($beat->object));
            }

            if ($beat->target !== null) {
                $activity->target($this->cast->party($beat->target));
            }

            if ($beat->context !== null) {
                $activity->context($this->cast->party($beat->context));
            }

            $activity->publish();
            $published++;
        }

        if ($published > 0) {
            // Deliberate cache loss, not a deleted Party or a non-Feedable model.
            // A dedicated identity keeps the damaged snapshot out of other beats.
            $missing = $this->cast->party('Unavailable demo document');
            Storyfeed::activity(Vocabulary::APPROVE)
                ->actor($this->cast->party($this->cast->members[0]))
                ->object($missing)
                ->publishedAt(now()->startOfDay())
                ->data(['demo' => true, 'degradation' => 'snapshot-loss'])
                ->publish();
            $snapshotModel = config('storyfeed.models.snapshot', Snapshot::class);
            $snapshotModel::query()
                ->where('model_type', $missing->getMorphClass())
                ->where('model_id', $missing->getKey())
                ->delete();
            $published++;

            SyncToken::bump();
        }

        return $published;
    }

    /**
     * Remove everything this kit has ever published, and the parties it created.
     *
     * @return array{activities: int, parties: int}
     */
    public static function fresh(): array
    {
        $activityModel = config('storyfeed.models.activity', Activity::class);
        $groupingModel = config('storyfeed.models.grouping', Grouping::class);
        $partyModel = config('storyfeed.models.party', Party::class);

        $activities = 0;

        // Chunked and mirroring PruneActivities: grouping rows have no DB-level
        // cascade by design, and participant rows are forgotten explicitly.
        while (true) {
            $ids = $activityModel::query()
                ->withTrashed()
                ->where('verb', 'like', Vocabulary::PREFIX.'%')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $groupingModel::query()->whereIn('activity_id', $ids)->delete();

            SyncParticipants::forget(...$ids);

            $activityModel::query()->withTrashed()->whereKey($ids)->forceDelete();

            $activities += $ids->count();
        }

        // Fresh means no trace: the kit's force-delete records no removal
        // evidence, and evidence from demo rows deleted during play goes too.
        Removals::query()->where('verb', 'like', Vocabulary::PREFIX.'%')->delete();

        $parties = $partyModel::query()->where('key', 'like', 'demo-%')->delete();

        if ($activities > 0 || $parties > 0) {
            SyncToken::bump();
        }

        return ['activities' => $activities, 'parties' => (int) $parties];
    }
}
