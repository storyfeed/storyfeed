<?php

namespace Storyfeed\Events\Snapshots;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Models\Activity;
use Storyfeed\Support\ActivityRoles;

/** Immutable event-time facts, never an Eloquent model or a live relation. */
final readonly class ActivitySnapshot
{
    use HasPayload;

    /**
     * @param  array<string, mixed>|null  $actor
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>|null  $target
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>|null  $origin
     * @param  array<string, mixed>|null  $result
     * @param  array<string, mixed>|null  $instrument
     * @param  array<string, mixed>|null  $location
     * @param  array<string, mixed>|null  $generator
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(
        public int $id,
        public string $uid,
        public string $verb,
        public ?array $actor,
        public ?array $object,
        public ?array $target,
        public ?array $context,
        public array $data,
        public ?string $published_at,
        public ?string $deleted_at,
        public bool $forceDeleted,
        public ?array $origin = null,
        public ?array $result = null,
        public ?array $instrument = null,
        public ?array $location = null,
        public ?array $generator = null,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        $activity->loadMissing(ActivityRoles::cachedRelations());

        $roles = [];
        foreach (ActivityRoles::STORED as $role) {
            $type = $activity->getAttribute($role.'_type');
            $id = $activity->getAttribute($role.'_id');
            $cached = $activity->{'cached'.ucfirst($role)};
            $roles[$role] = $type === null ? null : [
                'type' => $type,
                'id' => $id === null ? null : (string) $id,
                'label' => $cached?->label,
                'data' => PlainData::freeze($cached->data ?? []),
                'content' => $cached?->content,
                'mediaType' => $cached?->media_type,
                'attributedTo' => $cached?->attributed_to,
            ];
        }

        return new self(
            $activity->id, $activity->uid, $activity->verb,
            $roles['actor'], $roles['object'], $roles['target'], $roles['context'],
            PlainData::freeze($activity->data ?? []),
            $activity->published_at?->toIso8601String(),
            $activity->deleted_at?->toIso8601String(),
            $activity->isForceDeleting() || ! $activity->exists,
            $roles['origin'],
            $roles['result'],
            $roles['instrument'],
            $roles['location'],
            $roles['generator'],
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        // All nine roles are now public payload facts, including explicit nulls.
        // Promoted constructor defaults do not initialize properties on unserialize.
        $payload = get_object_vars($this) + array_fill_keys(ActivityRoles::PAYLOAD, null);

        // Older queued snapshots may still contain numeric role ids.
        foreach (ActivityRoles::STORED as $role) {
            if (isset($payload[$role]['id'])) {
                $payload[$role]['id'] = (string) $payload[$role]['id'];
            }
        }

        return $payload;
    }
}
