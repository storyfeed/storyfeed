<?php

namespace Storyfeed\Detail;

use InvalidArgumentException;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedDetail;

/**
 * Named fields with their before and after values, in authored order.
 *
 * Index 0 is before and index 1 is after. Omit an index for an added or removed
 * field; null is a present value. Values stay scalar or null: coercing an object
 * would lose its meaning, and accepting arrays would allow details to nest.
 * An empty map is legal and has nothing to draw.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the detail at read time, never write it back.
 */
class Change implements FeedDetail
{
    use HasPayload;

    /** @param array<string, array<int, scalar|null>> $changes */
    final protected function __construct(
        private readonly array $changes,
    ) {}

    /** @param array<array-key, mixed> $changes */
    public static function make(array $changes): static
    {
        foreach ($changes as $field => $pair) {
            if (! is_string($field) || ! self::validPair($pair)) {
                throw new InvalidArgumentException('Changes require named fields with scalar or null values at index 0 (before) and/or 1 (after).');
            }
        }

        return new static($changes);
    }

    /**
     * `Storyfeed/Detail/Change` — the VOCABULARY'S name, not a package's.
     *
     * A detail outlives whichever library defined it ({@see FeedDetail}), so the
     * name must not contain the library: this form has already moved packages
     * once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix would have
     * moved with it. The name is a pure lookup key — no reflection, no
     * autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function name(): string
    {
        return 'Storyfeed/Detail/Change';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // No other version has a defined shape yet. A reader with an older
        // vocabulary draws nothing rather than guessing at a newer row.
        if ($from !== 1 || ! is_array($payload['changes'] ?? null)) {
            return ['changes' => []];
        }

        $changes = [];

        foreach ($payload['changes'] as $field => $pair) {
            if (is_string($field) && self::validPair($pair)) {
                $changes[$field] = $pair;
            }
        }

        return ['changes' => $changes];
    }

    /** @return array{'$detail': string, '$v': int, changes: array<string, array<int, scalar|null>>} */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'changes' => $this->changes,
        ];
    }

    private static function validPair(mixed $pair): bool
    {
        if (! is_array($pair) || $pair === []) {
            return false;
        }

        foreach ($pair as $index => $value) {
            if (($index !== 0 && $index !== 1)
                || (! is_scalar($value) && $value !== null)
                || (is_float($value) && ! is_finite($value))) {
                return false;
            }
        }

        return true;
    }
}
