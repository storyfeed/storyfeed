<?php

namespace Storyfeed\BodyMigrations;

/** The Laravel JSON Schema subset used by body declarations. */
final class Shape
{
    /** @param array<string, mixed> $schema */
    public static function matches(mixed $value, array $schema): bool
    {
        $supported = ['type', 'properties', 'required', 'additionalProperties', 'items', 'anyOf', 'enum', 'minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'title', 'description', 'default'];
        if (array_diff(array_keys($schema), $supported)) {
            // Never silently claim validation of a keyword this prototype does not implement.
            return false;
        }
        if (isset($schema['anyOf'])) {
            $matches = false;
            foreach ($schema['anyOf'] as $choice) {
                $matches = $matches || self::matches($value, $choice);
            }
            if (! $matches) {
                return false;
            }
        }
        if (isset($schema['type'])) {
            $matches = false;
            foreach ((array) $schema['type'] as $type) {
                $matches = $matches || match ($type) {
                    'null' => $value === null,
                    'string' => is_string($value),
                    'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value),
                    'boolean' => is_bool($value),
                    'array' => is_array($value) && array_is_list($value),
                    'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                    default => false,
                };
            }
            if (! $matches) {
                return false;
            }
        }
        if ($value === null && in_array('null', (array) ($schema['type'] ?? []), true)) {
            return true;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])) {
                return false;
            }
            if (isset($schema['multipleOf'])) {
                if ($schema['multipleOf'] <= 0) {
                    return false;
                }
                $quotient = $value / $schema['multipleOf'];
                if (abs($quotient - round($quotient)) > 1e-9) {
                    return false;
                }
            }
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            if ((isset($schema['minLength']) && $length < $schema['minLength']) || (isset($schema['maxLength']) && $length > $schema['maxLength'])) {
                return false;
            }
            if (isset($schema['pattern']) && @preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value) !== 1) {
                return false;
            }
        }
        if (! is_array($value)) {
            return true;
        }
        if ((isset($schema['minItems']) && count($value) < $schema['minItems']) || (isset($schema['maxItems']) && count($value) > $schema['maxItems'])) {
            return false;
        }
        if (($schema['uniqueItems'] ?? false) && count(array_unique(array_map(serialize(...), $value))) !== count($value)) {
            return false;
        }
        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $value)) {
                return false;
            }
        }
        foreach ($schema['properties'] ?? [] as $field => $property) {
            if (array_key_exists($field, $value) && ! self::matches($value[$field], $property)) {
                return false;
            }
        }
        if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($schema['properties'] ?? []))) {
            return false;
        }
        if (isset($schema['items'])) {
            foreach ($value as $item) {
                if (! self::matches($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
