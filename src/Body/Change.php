<?php

namespace Storyfeed\Body;

use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;

/**
 * Named fields with their before and after values, in authored order.
 *
 * Index 0 is before and index 1 is after. Omit an index for an added or removed
 * field; null is a present value. Values stay scalar or null: coercing an object
 * would lose its meaning, and accepting arrays would allow bodies to nest.
 * An empty map is legal and has nothing to draw.
 *
 *     Change::make()->field('Status', 'Draft', 'Ready')->field('Owner', 'Ana', 'Ben')
 *     Change::make(['Status' => ['Draft', 'Ready'], 'Owner' => ['Ana', 'Ben']])
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the body at read time, never write it back.
 */
class Change implements FeedBody
{
    use Conditionable;
    use HasPayload;

    /** @var array<string, array<int, scalar|null>> */
    private array $changes = [];

    final protected function __construct() {}

    /**
     * Start a change. `$changes` is optional and is the same as calling
     * {@see changes()} with it.
     *
     * @param  array<array-key, mixed>  $changes  `field => [before, after]`
     */
    public static function make(array $changes = []): static
    {
        return (new static)->changes($changes);
    }

    /**
     * Add fields. A map MERGES: a field already here takes the later pair and
     * keeps its place. Omit index 0 for an added field, index 1 for a removed one.
     *
     * @param  array<array-key, mixed>  $changes  `field => [before, after]`
     */
    public function changes(array $changes): static
    {
        foreach ($changes as $field => $pair) {
            if (! is_string($field) || ! self::validPair($pair)) {
                throw new InvalidArgumentException('Changes require named fields with scalar or null values at index 0 (before) and/or 1 (after).');
            }

            /** @var array<int, scalar|null> $pair */
            $this->changes[$field] = $pair;
        }

        return $this;
    }

    /**
     * Add one field that went from `$before` to `$after`, after the fields
     * already here. A field already here takes the new pair in its place.
     */
    public function field(string $name, string|int|float|bool|null $before, string|int|float|bool|null $after): static
    {
        return $this->changes([$name => [$before, $after]]);
    }

    /**
     * `Storyfeed/Body/Change` — the VOCABULARY'S name, not a package's.
     *
     * A body outlives whichever library defined it ({@see FeedBody}), so the
     * name must not contain the library: this body type has already moved
     * packages once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix
     * would have moved with it. The name is a pure lookup key — no reflection,
     * no autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Change';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // No other version has a defined shape yet. A reader with an older
        // vocabulary draws nothing rather than guessing at a newer row.
        if ($from !== 1 || ! is_array($payload['items'] ?? null)) {
            return ['items' => []];
        }

        $changes = [];

        foreach ($payload['items'] as $field => $pair) {
            if (is_string($field) && self::validPair($pair)) {
                $changes[$field] = $pair;
            }
        }

        return ['items' => $changes];
    }

    /** @return array{'$body': string, '$v': int, items: array<string, array<int, scalar|null>>} */
    public function toPayload(): array
    {
        return [
            self::KEY => self::bodyType(),
            self::VERSION => self::version(),
            'items' => $this->changes,
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
