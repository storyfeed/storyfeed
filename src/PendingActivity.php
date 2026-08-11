<?php

namespace Storyfeed;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Exceptions\IncompleteActivity;
use Storyfeed\Exceptions\UnknownVerb;
use Storyfeed\Models\Activity;

/**
 * Fluent builder for publishing activities.
 *
 *   Storyfeed::activity(ActivityVerb::Confirm, $delivery)
 *       ->actor($user)
 *       ->for($customer)
 *       ->publish();
 *
 * Verbs are free-form strings; a FeedVerb enum (or any backed enum) is an
 * authoring convenience that resolves to the same string.
 *
 * @phpstan-consistent-constructor
 */
class PendingActivity
{
    use Conditionable;

    public Activity $activity;

    protected bool $replace = false;

    /** @var array<string, Model> */
    protected array $entities = [];

    public function __construct(string|FeedVerb|BackedEnum|null $verb = null, ?Model $object = null)
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $this->activity = new $model;

        if ($verb !== null) {
            $this->verb($verb, $object);
        }
    }

    public static function make(string|FeedVerb|BackedEnum|null $verb = null, ?Model $object = null): static
    {
        return new static($verb, $object);
    }

    public function verb(string|FeedVerb|BackedEnum $verb, ?Model $object = null): static
    {
        $this->activity->verb = $this->normalizeVerb($verb);

        if ($object) {
            $this->object($object);
        }

        return $this;
    }

    public function actor(?Model $model = null): static
    {
        return $this->associate('actor', $model);
    }

    public function object(?Model $model = null): static
    {
        return $this->associate('object', $model);
    }

    public function target(?Model $model = null): static
    {
        return $this->associate('target', $model);
    }

    public function context(?Model $model = null): static
    {
        return $this->associate('context', $model);
    }

    public function in(?Model $model = null): static
    {
        return $this->target($model);
    }

    public function to(?Model $model = null): static
    {
        return $this->target($model);
    }

    public function for(?Model $model = null): static
    {
        return $this->target($model);
    }

    public function from(?Model $model = null): static
    {
        return $this->target($model);
    }

    public function data(array $data): static
    {
        $this->activity->data = $data;

        return $this;
    }

    public function publishedAt(DateTimeInterface|string $date): static
    {
        $this->activity->published_at = $date instanceof DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse($date);

        return $this;
    }

    public function replace(bool $replace = true): static
    {
        $this->replace = $replace;

        return $this;
    }

    public function publishAndReplace(): Activity
    {
        return $this->replace()->publish();
    }

    public function publish(): Activity
    {
        if (blank($this->activity->verb)) {
            throw IncompleteActivity::missingVerb();
        }

        $activity = DB::transaction(function () {
            $this->snapshotEntities();

            $this->activity->save();

            $this->writeGroupings();

            if ($this->replace && $this->activity->object_id !== null) {
                $this->activity->newQuery()
                    ->whereKeyNot($this->activity->getKey())
                    ->where('object_type', $this->activity->object_type)
                    ->where('object_id', $this->activity->object_id)
                    ->where('verb', $this->activity->verb)
                    ->delete();
            }

            return $this->activity;
        });

        ActivityPublished::dispatch($activity);

        return $activity;
    }

    /**
     * Verbs are stored verbatim apart from trimming — no case folding, since
     * camelCase verbs like `updateStatus` are valid and folding would break
     * grammar keys and grouping hashes.
     */
    protected function normalizeVerb(string|FeedVerb|BackedEnum $verb): string
    {
        $resolved = match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => trim($verb),
        };

        if ($resolved === '') {
            throw IncompleteActivity::missingVerb();
        }

        if ($this->strictVerbs() && app(StoryfeedManager::class)->activityType($resolved) === null) {
            throw UnknownVerb::make($resolved);
        }

        return $resolved;
    }

    /**
     * Strict verbs are a development-time assertion. Unset means "strict
     * where mistakes are cheap to fix" — never in production.
     */
    private function strictVerbs(): bool
    {
        $strict = config('storyfeed.verbs.strict');

        return $strict ?? app()->environment('local', 'testing');
    }

    private function associate(string $role, ?Model $model): static
    {
        if ($model instanceof Model) {
            $this->activity->{$role}()->associate($model);
            $this->entities[$role] = $model;
        }

        return $this;
    }

    /**
     * Write one candidate grouping hash per axis, for curation to select
     * among at read time.
     */
    private function writeGroupings(): void
    {
        (new WriteGroupings)($this->activity);
    }

    /**
     * Snapshot every Feedable entity synchronously, inside the publish
     * transaction, so a new activity is never invisible or degraded.
     */
    private function snapshotEntities(): void
    {
        foreach ($this->entities as $role => $model) {
            if ($model instanceof Feedable) {
                $snapshot = (new SnapshotEntity)($model);

                $this->activity->{'cached_'.$role.'_id'} = $snapshot->getKey();
            }
        }
    }
}
