<?php

namespace Storyfeed;

use Storyfeed\Concerns\HasPayload;

/**
 * The before/after facts of one activity, captured before the old values vanish.
 * Labels are the app's words; null means an empty or cleared value. Core stores
 * both sides, never a rendered diff or a translated sentence.
 *
 * Core owns KEY, so it upgrades on read and never exposes VERSION to renderers.
 * This is not a portable Contracts\FeedDetail and mints no Activity Streams term.
 */
final class FeedChange
{
    use HasPayload;

    public const string KEY = '$change';

    public const string VERSION = '$v';

    /** @param list<array{label: string, before: string|null, after: string|null}> $changes */
    private function __construct(public private(set) array $changes) {}

    /**
     * @param  array<array-key, mixed>  $changes  Label => [before, after], or explicit rows.
     */
    public static function make(array $changes): self
    {
        $rows = [];

        foreach ($changes as $key => $change) {
            if (! is_array($change)) {
                continue;
            }

            $explicit = array_key_exists('label', $change)
                || array_key_exists('before', $change) || array_key_exists('after', $change);
            $label = $explicit ? ($change['label'] ?? $key) : $key;

            $rows[] = [
                'label' => is_scalar($label) ? (string) $label : (string) $key,
                'before' => self::side($explicit ? ($change['before'] ?? null) : ($change[0] ?? null)),
                'after' => self::side($explicit ? ($change['after'] ?? null) : ($change[1] ?? null)),
            ];
        }

        return new self($rows);
    }

    private static function side(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    public static function version(): int
    {
        return 1;
    }

    /** Missing or invalid versions mean version 1, never the current version. */
    public static function versionOf(mixed $value): int
    {
        $version = is_array($value) ? ($value[self::VERSION] ?? null) : null;

        return is_int($version) && $version > 0 ? $version : 1;
    }

    /**
     * Read-time only; unknown versions retain the fields this reader recognises.
     * Version 1 is currently the only shape, so every upgrade is the identity.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        return $payload;
    }

    /** Total: malformed rows degrade without rewriting stored history. */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $value = self::upgrade($value, self::versionOf($value));

        return self::make(is_array($value['changes'] ?? null) ? $value['changes'] : []);
    }

    /** @return array{changes: list<array{label: string, before: string|null, after: string|null}>} */
    public function toPayload(): array
    {
        return ['changes' => $this->changes];
    }

    /** @return array{changes: list<array{label: string, before: string|null, after: string|null}>, '$v': int} */
    public function toArray(): array
    {
        return [...$this->toPayload(), self::VERSION => self::version()];
    }

    /**
     * Record through either record(data: ...) surface without spelling storage keys:
     * data: FeedChange::make(['Status' => ['Draft', 'Ready']])->toData(['source' => 'import']).
     * The typed value wins a collision with its own reserved key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function toData(array $data = []): array
    {
        return [...$data, self::KEY => $this->toArray()];
    }
}
