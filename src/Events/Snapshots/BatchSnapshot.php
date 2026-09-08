<?php

namespace Storyfeed\Events\Snapshots;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Models\Batch;
use Storyfeed\Support\ActivityRoles;

/** A digest freezes its members at close, before automatic bundling. */
final readonly class BatchSnapshot
{
    use HasPayload;

    /**
     * @param  list<ActivitySnapshot>  $activities
     * @param  array<array-key, mixed>  $meta
     */
    private function __construct(
        public int $id,
        public string $uid,
        public ?string $actor_type,
        public int|string|null $actor_id,
        public string $opened_at,
        public ?string $closed_at,
        public ?string $last_activity_at,
        public int $activities_count,
        public array $meta,
        public array $activities,
    ) {}

    public static function fromModel(Batch $batch): self
    {
        $members = $batch->activities()->with(ActivityRoles::cachedRelations())
            ->orderBy('published_at')->orderBy('id')->get();

        return new self(
            $batch->id, $batch->uid, $batch->actor_type, $batch->actor_id,
            $batch->opened_at->toIso8601String(), $batch->closed_at?->toIso8601String(),
            $batch->last_activity_at?->toIso8601String(), $batch->activities_count,
            PlainData::freeze($batch->meta ?? []),
            $members->map(fn ($activity) => ActivitySnapshot::fromModel($activity))->values()->all(),
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return array_replace(get_object_vars($this), [
            'activities' => array_map(fn (ActivitySnapshot $activity) => $activity->toPayload(), $this->activities),
        ]);
    }
}
