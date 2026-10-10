<?php

namespace Storyfeed\Grouping;

use Illuminate\Contracts\Support\Arrayable;
use UnexpectedValueException;

/**
 * A group's public id, shaped like Laravel's pagination Cursor: built from
 * the group's axis and grouping hash, `encode()` gives the group node's
 * `id`, and `fromEncoded()` reads one back, so `members()` can find the
 * group again. The string is opaque and only its stability is contract.
 *
 * The version travels in the encoding, so a later version can carry more
 * (a time window, a shard hint, a row id) without a payload change.
 * `fromEncoded()` reads every version that was ever emitted.
 *
 * @implements Arrayable<string, string>
 */
final class Anchor implements Arrayable
{
    private const PREFIX = 'grp_';

    /** The version encode() writes. */
    private const VERSION = 'v2';

    /** @var array<string, string> */
    protected array $parameters;

    public function __construct(string $axis, string $hash)
    {
        $this->parameters = ['axis' => $axis, 'hash' => $hash];
    }

    /**
     * Get the given parameter from the anchor.
     *
     * @throws UnexpectedValueException
     */
    public function parameter(string $parameterName): string
    {
        if (! array_key_exists($parameterName, $this->parameters)) {
            throw new UnexpectedValueException("Unable to find parameter [{$parameterName}] in group anchor.");
        }

        return $this->parameters[$parameterName];
    }

    /**
     * Get the given parameters from the anchor.
     *
     * @param  array<int, string>  $parameterNames
     * @return array<int, string>
     */
    public function parameters(array $parameterNames): array
    {
        return array_map(fn (string $parameterName) => $this->parameter($parameterName), $parameterNames);
    }

    /**
     * Get the array representation of the anchor.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->parameters;
    }

    /**
     * Get the encoded string representation of the anchor: a group node's `id`.
     */
    public function encode(): string
    {
        $payload = implode("\x1f", [self::VERSION, $this->parameters['axis'], $this->parameters['hash']]);

        return self::PREFIX.str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    }

    /**
     * Get an anchor instance from the encoded string representation, or
     * null when the string is not a group id.
     */
    public static function fromEncoded(mixed $encodedString): ?self
    {
        if (! is_string($encodedString) || ! str_starts_with($encodedString, self::PREFIX)) {
            return null;
        }

        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], substr($encodedString, strlen(self::PREFIX))), true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode("\x1f", $decoded);

        return match ($parts[0]) {
            // v1 was a one-way SHA-1 and never decodable.
            'v2' => self::fromParts(array_slice($parts, 1)),
            default => null,
        };
    }

    /** @param  array<int, string>  $parts */
    private static function fromParts(array $parts): ?self
    {
        // The axis is a registered name; a hash may itself contain the separator.
        $axis = array_shift($parts);
        $hash = implode("\x1f", $parts);

        return $axis === null || $axis === '' || $hash === '' ? null : new self($axis, $hash);
    }
}
