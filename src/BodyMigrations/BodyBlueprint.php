<?php

namespace Storyfeed\BodyMigrations;

use Closure;
use InvalidArgumentException;

final class BodyBlueprint
{
    /** @var list<Closure(array<string, mixed>): array<string, mixed>> */
    private array $operations = [];

    public function renameField(string $from, string $to): void
    {
        $this->field($from);
        $this->field($to);
        $this->operations[] = static function (array $payload) use ($from, $to): array {
            if (! array_key_exists($to, $payload) && array_key_exists($from, $payload)) {
                $payload[$to] = $payload[$from];
            }
            unset($payload[$from]);

            return $payload;
        };
    }

    public function dropField(string $field): void
    {
        $this->field($field);
        $this->operations[] = static function (array $payload) use ($field): array {
            unset($payload[$field]);

            return $payload;
        };
    }

    public function addField(string $field, mixed $default = null): void
    {
        $this->field($field);
        $this->operations[] = static function (array $payload) use ($field, $default): array {
            if (! array_key_exists($field, $payload)) {
                $payload[$field] = $default;
            }

            return $payload;
        };
    }

    /** @param Closure(mixed): mixed $change */
    public function changeField(string $field, Closure $change): void
    {
        $this->field($field);
        $this->operations[] = static function (array $payload) use ($field, $change): array {
            $payload[$field] = $change($payload[$field] ?? null);

            return $payload;
        };
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        foreach ($this->operations as $operation) {
            $payload = $operation($payload);
        }

        return $payload;
    }

    private function field(string $field): void
    {
        if ($field === '' || str_starts_with($field, '$')) {
            throw new InvalidArgumentException('Body migrations cannot change reserved fields.');
        }
    }
}
