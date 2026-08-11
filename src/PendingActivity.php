<?php

namespace Storyfeed;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Models\Activity;

/**
 * Fluent builder for publishing activities.
 *
 *   Storyfeed::activity()->actor($user)->verb('create')->object($project)->publish();
 *   Storyfeed::activity()->actor($user)->confirm($delivery)->for($customer)->publish();
 *
 * `__call` maps any unknown method to verb(), so ->confirm($obj) works.
 * publishAndReplace() collapses "latest wins" verbs (e.g. repeated saves).
 *
 * @method self create(?Model $object = null)
 * @method self update(?Model $object = null)
 * @method self delete(?Model $object = null)
 *
 * @phpstan-consistent-constructor
 */
class PendingActivity
{
    public Activity $activity;

    protected bool $replace = false;

    /** @var array<string, Model> */
    protected array $entities = [];

    public function __construct(...$args)
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $this->activity = new $model(...$args);
    }

    public static function make(...$args): static
    {
        return new static(...$args);
    }

    public function __call(string $name, array $args): static
    {
        return $this->verb($name, ...$args);
    }

    public function verb(string $verb, ?Model $object = null): static
    {
        $this->activity->verb = $verb;

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

    protected function associate(string $role, ?Model $model): static
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
    protected function writeGroupings(): void
    {
        (new WriteGroupings)($this->activity);
    }

    /**
     * Snapshot every Feedable entity synchronously, inside the publish
     * transaction, so a new activity is never invisible or degraded.
     */
    protected function snapshotEntities(): void
    {
        foreach ($this->entities as $role => $model) {
            if ($model instanceof Feedable) {
                $snapshot = (new SnapshotEntity)($model);

                $this->activity->{'cached_'.$role.'_id'} = $snapshot->getKey();
            }
        }
    }
}
