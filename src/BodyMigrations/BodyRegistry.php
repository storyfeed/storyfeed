<?php

namespace Storyfeed\BodyMigrations;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Diagnostics\Finding;
use Throwable;

final class BodyRegistry
{
    public const UNREADABLE = 'Storyfeed/Body/Unreadable';

    public bool $enabled = false;

    /** @var \WeakMap<FeedBody, array<string, mixed>> */
    private \WeakMap $schemas;

    /** @var array<string, FeedBody> */
    private array $bodies = [];

    /** @var array<string, true> */
    private array $reported = [];

    /** @var array<string, Finding> */
    private array $findings = [];

    public function __construct()
    {
        $this->schemas = new \WeakMap;
        foreach ([KeyValue::make(), MediaObject::make(), FileAttachment::make(), ItemList::make(), Prose::make(''), Excerpt::make(''), Component::make('example')] as $body) {
            $this->register($body);
        }
    }

    /** An example supplies current writer fields when no schema is declared. */
    public function register(FeedBody $example): void
    {
        $type = $example::bodyType();
        if (isset($this->bodies[$type]) && $example::class !== $this->bodies[$type]::class) {
            throw new LogicException("Body type {$type} is already registered.");
        }
        $this->bodies[$type] = $example;
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return array_values($this->findings);
    }

    public function clearFindings(): void
    {
        $this->findings = [];
    }

    /** @return array<string, mixed> */
    public function schema(FeedBody $body): array
    {
        if (isset($this->schemas[$body])) {
            return $this->schemas[$body];
        }
        if (method_exists($body, 'schema') && class_exists(JsonSchemaTypeFactory::class)) {
            $factory = new JsonSchemaTypeFactory;

            return $this->schemas[$body] = $factory->object($body->schema($factory))->withoutAdditionalProperties()->toArray();
        }
        $sample = $body->toPayload();
        unset($sample[FeedBody::KEY], $sample[FeedBody::VERSION]);

        return $this->schemas[$body] = ['type' => 'object', 'required' => array_keys($sample), 'properties' => array_fill_keys(array_keys($sample), []), 'additionalProperties' => false];
    }

    /** Walk data maps, body lists, and nested body values with one shared rule. */
    public function walk(mixed $value, bool $persist = false): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value[FeedBody::KEY]) && is_string($value[FeedBody::KEY])) {
            return $this->body($value, $persist);
        }

        return array_map(fn (mixed $child): mixed => $this->walk($child, $persist), $value);
    }

    /** @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function body(array $stored, bool $persist): array
    {
        $type = $stored[FeedBody::KEY];
        $body = $this->bodies[$type] ?? null;
        if ($body === null) {
            return $stored;
        }
        $from = $stored[FeedBody::VERSION] ?? 1;
        try {
            if (! is_int($from) || $from < 1 || $from > $body::version()) {
                throw new LogicException('The stored version is not supported.');
            }
            $payload = $stored;
            unset($payload[FeedBody::KEY], $payload[FeedBody::VERSION]);
            $payload = $body::upgrade($payload, $from);
            if (! Shape::matches($payload, $this->schema($body))) {
                throw new LogicException('The upgraded fields do not match the current writer.');
            }
            $payload = array_map(fn (mixed $child): mixed => $this->walk($child, $persist), $payload);

            return [FeedBody::KEY => $type, FeedBody::VERSION => $body::version(), ...$payload];
        } catch (Throwable $exception) {
            $version = is_int($from) ? $from : 1;
            $key = $type.':'.$version;
            $message = "{$type} v{$version} rows don't match the current shape; add a body migration";
            $this->findings[$key] ??= Finding::warning('body.unreadable', $message, ['type' => $type, 'storedVersion' => $version]);
            if (! isset($this->reported[$key])) {
                $this->reported[$key] = true;
                try {
                    $log = Cache::add('storyfeed:body-unreadable:'.hash('sha256', $key), true, now()->addDay());
                } catch (Throwable) {
                    // A missing cache table must not make a body hide the activity.
                    $log = true;
                }
                if ($log) {
                    try {
                        Log::warning($message, ['reason' => $exception->getMessage()]);
                    } catch (Throwable) {
                        // Diagnostics must not turn a placeholder into a missing activity.
                    }
                }
            }

            return $persist ? $stored : [FeedBody::KEY => self::UNREADABLE, FeedBody::VERSION => 1, 'type' => $type, 'storedVersion' => $version];
        }
    }
}
