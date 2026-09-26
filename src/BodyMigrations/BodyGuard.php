<?php

namespace Storyfeed\BodyMigrations;

use LogicException;
use Storyfeed\Contracts\FeedBody;

final class BodyGuard
{
    /** @return array{type: string, history: list<array{name: string, hash: string}>, schema: array<string, mixed>} */
    public static function snapshot(FeedBody $body): array
    {
        $schema = app(BodyRegistry::class)->schema($body);
        $payload = $body->toPayload();
        unset($payload[FeedBody::KEY], $payload[FeedBody::VERSION]);
        if (! Shape::matches($payload, $schema)) {
            throw new LogicException($body::bodyType().': toPayload() does not match schema(). Fields: '.implode(', ', array_keys($payload)));
        }

        return ['type' => $body::bodyType(), 'history' => app(BodyMigrator::class)->history($body::class), 'schema' => $schema];
    }

    /** Compare against each branch's committed baseline as well as the released baseline.
     * @param  array{type: string, history: list<array{name: string, hash: string}>, schema: array<string, mixed>}  $released
     * @param  array{type: string, history: list<array{name: string, hash: string}>, schema: array<string, mixed>}  $current
     */
    public static function assertCompatible(array $released, array $current): void
    {
        $type = $released['type'];
        if ($current['type'] !== $type) {
            throw new LogicException("{$type}: body type identities cannot change.");
        }
        foreach ($released['history'] as $position => $step) {
            if (($current['history'][$position] ?? null) !== $step) {
                $version = $position + 2;
                throw new LogicException("{$type}: migration history diverges at v{$version}; expected {$step['name']}. Reconcile branches before release; never reorder or edit released migrations.");
            }
        }
        if ($released['schema'] !== $current['schema'] && count($released['history']) === count($current['history'])) {
            $old = $released['schema']['properties'] ?? [];
            $new = $current['schema']['properties'] ?? [];
            $removed = implode(', ', array_diff(array_keys($old), array_keys($new)));
            $added = implode(', ', array_diff(array_keys($new), array_keys($old)));
            $changed = implode(', ', array_keys(array_filter($new, fn (mixed $value, string $key): bool => isset($old[$key]) && $old[$key] !== $value, ARRAY_FILTER_USE_BOTH)));
            throw new LogicException("{$type}: removed [{$removed}], added [{$added}], changed [{$changed}]; no body migration added.");
        }
    }
}
