<?php

namespace Storyfeed\Serialization;

use Illuminate\Support\Carbon;
use Storyfeed\StoryfeedManager;

/**
 * Parses Storyfeed's OWN AS2.0 documents back into activity attributes —
 * the round-trip half of conformance CI (serialize → read → compare).
 *
 * This is not a general AS2/ActivityPub consumer (an explicit v1 non-goal);
 * it reads the document shape ActivitySerializer emits. `sf:verb` is the
 * source of truth for the verb; the mapped `type` is only a fallback,
 * reverse-mapped through the registry.
 */
class Reader
{
    public function __construct(
        protected StoryfeedManager $storyfeed,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array{uid: string|null, verb: string|null, type: string|null, published_at: Carbon|null, actor: array<string, mixed>|null, object: array<string, mixed>|null, target: array<string, mixed>|null, context: array<string, mixed>|null}
     */
    public function activity(array $document): array
    {
        return [
            'uid' => $this->uid($document),
            'verb' => $this->verb($document),
            'type' => $document['type'] ?? null,
            'published_at' => isset($document['published']) ? Carbon::parse($document['published']) : null,
            'actor' => $document['actor'] ?? null,
            'object' => $document['object'] ?? null,
            'target' => $document['target'] ?? null,
            'context' => $document['context'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function verb(array $document): ?string
    {
        $verb = $document['sf:verb'] ?? null;

        if (is_string($verb) && $verb !== '') {
            return $verb;
        }

        // Foreign document: reverse-map the AS2 type through the registry.
        // First registered verb wins; ambiguity is acceptable in a fallback.
        $type = $document['type'] ?? null;

        foreach ($this->storyfeed->registeredVerbs() as $registered => $mapped) {
            if ($this->storyfeed->activityTypeValue($registered) === $type) {
                return $registered;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function uid(array $document): ?string
    {
        $id = $document['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $basename = basename(parse_url($id, PHP_URL_PATH) ?: '');

        return $basename === '' ? null : $basename;
    }
}
