<?php

namespace Storyfeed\Events\Snapshots;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Models\Activity;

/** Immutable event-time facts, never an Eloquent model or a live relation. */
final readonly class ActivitySnapshot
{
    use HasPayload;

    /**
     * @param  array<string, mixed>|null  $actor
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>|null  $target
     * @param  array<string, mixed>|null  $context
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
    ) {}

    public static function fromModel(Activity $activity): self
    {
        $activity->loadMissing(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']);

        $roles = [];
        foreach (['actor', 'object', 'target', 'context'] as $role) {
            $type = $activity->getAttribute($role.'_type');
            $id = $activity->getAttribute($role.'_id');
            $cached = $activity->getRelation('cached'.ucfirst($role));
            $roles[$role] = $type === null ? null : [
                'type' => $type,
                'id' => $id,
                'label' => $cached?->label,
                'component' => $cached?->component,
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
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
