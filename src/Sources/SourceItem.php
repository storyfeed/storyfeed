<?php

namespace Storyfeed\Sources;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\InlineEntity;
use Storyfeed\Support\MorphKeyType;

/**
 * One activity a source hands over: what happened, who and what took part,
 * and when. As an array, the same keys:
 *
 *   [
 *       'verb' => 'release',
 *       'actor' => 'Storyfeed',                                  // a party name
 *       'object' => ['type' => 'release', 'label' => 'v0.18.0', 'url' => 'https://…'],
 *       'context' => $project,                                   // a model
 *       'published_at' => '2026-10-20 09:00',
 *       'starts_at' => '2026-10-09',                             // optional, as ->startsAt()
 *       'data' => ['notes' => 12],
 *       'body' => 'Feed sources and the array source.',
 *       'id' => 'release-0.18.0',
 *   ]
 *
 * A role (actor, object, target, context, origin, result, instrument) is a
 * model, a party name, or an entity with no model behind it: an array with a
 * `type` and a `label`, and optionally a `url`, an `id`, `data` and a
 * `body`. An entity's `id` defaults to its label.
 *
 * `body` is the object's body, as on a Feedable's `toFeed()`: one body,
 * several, or a line of text. `id` is the payload's activity id; without
 * one, an id is derived from the item, so it stays the same from one read
 * to the next.
 */
final class SourceItem
{
    /** The keys an array item may carry; anything else is an error. */
    protected const KEYS = ['verb', 'published_at', 'starts_at', 'ends_at', 'data', 'body', 'id', ...ActivityRoles::STORED];

    /** The keys an entity array may carry. */
    protected const ENTITY_KEYS = InlineEntity::KEYS;

    public readonly string $verb;

    public readonly Carbon $publishedAt;

    public readonly ?Carbon $startsAt;

    public readonly ?Carbon $endsAt;

    /**
     * @param  array<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body
     * @param  Model|string|array<string, mixed>|null  $actor
     * @param  Model|string|array<string, mixed>|null  $object
     * @param  Model|string|array<string, mixed>|null  $target
     * @param  Model|string|array<string, mixed>|null  $context
     * @param  Model|string|array<string, mixed>|null  $origin
     * @param  Model|string|array<string, mixed>|null  $result
     * @param  Model|string|array<string, mixed>|null  $instrument
     */
    public function __construct(
        string|FeedVerb|BackedEnum $verb,
        DateTimeInterface|string $publishedAt,
        public readonly Model|string|array|null $actor = null,
        public readonly Model|string|array|null $object = null,
        public readonly Model|string|array|null $target = null,
        public readonly Model|string|array|null $context = null,
        public readonly Model|string|array|null $origin = null,
        public readonly Model|string|array|null $result = null,
        public readonly Model|string|array|null $instrument = null,
        public readonly array $data = [],
        public readonly string|FeedBody|iterable|null $body = null,
        public readonly ?string $id = null,
        DateTimeInterface|string|null $startsAt = null,
        DateTimeInterface|string|null $endsAt = null,
    ) {
        $verb = match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => $verb,
        };

        if ($verb === '' || str_contains($verb, '.')) {
            throw new InvalidArgumentException("Source item verb [{$verb}] must be a non-empty verb without a dot.");
        }

        $this->verb = $verb;
        $this->publishedAt = Carbon::parse($publishedAt);
        $this->startsAt = $startsAt === null ? null : Carbon::parse($startsAt);
        $this->endsAt = $endsAt === null ? null : Carbon::parse($endsAt);

        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt->lt($this->startsAt)) {
            throw new InvalidArgumentException("An activity cannot end [{$this->endsAt->toIso8601String()}] before it starts [{$this->startsAt->toIso8601String()}].");
        }

        foreach (ActivityRoles::STORED as $role) {
            if (is_array($this->{$role})) {
                self::assertEntity($role, $this->{$role});
            }
        }

        if ($this->body !== null && $this->object === null) {
            throw new InvalidArgumentException('A source item with a body needs an object: the body is the object\'s.');
        }
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $actor
     * @param  Model|string|array<string, mixed>|null  $object
     * @param  Model|string|array<string, mixed>|null  $target
     * @param  Model|string|array<string, mixed>|null  $context
     * @param  Model|string|array<string, mixed>|null  $origin
     * @param  Model|string|array<string, mixed>|null  $result
     * @param  Model|string|array<string, mixed>|null  $instrument
     * @param  array<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body
     */
    public static function make(
        string|FeedVerb|BackedEnum $verb,
        DateTimeInterface|string $publishedAt,
        Model|string|array|null $actor = null,
        Model|string|array|null $object = null,
        Model|string|array|null $target = null,
        Model|string|array|null $context = null,
        Model|string|array|null $origin = null,
        Model|string|array|null $result = null,
        Model|string|array|null $instrument = null,
        array $data = [],
        string|FeedBody|iterable|null $body = null,
        ?string $id = null,
        DateTimeInterface|string|null $startsAt = null,
        DateTimeInterface|string|null $endsAt = null,
    ): self {
        return new self($verb, $publishedAt, $actor, $object, $target, $context, $origin, $result, $instrument, $data, $body, $id, $startsAt, $endsAt);
    }

    /**
     * An item from its array form. A missing verb or published_at, or a key
     * this class does not know, throws.
     *
     * @param  self|array<string, mixed>  $item
     */
    public static function from(self|array $item): self
    {
        if ($item instanceof self) {
            return $item;
        }

        if (($unknown = array_diff(array_keys($item), self::KEYS)) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown source item key [%s]. Items take: %s.', implode(', ', $unknown), implode(', ', self::KEYS),
            ));
        }

        foreach (['verb', 'published_at'] as $required) {
            if (! isset($item[$required])) {
                throw new InvalidArgumentException("A source item needs [{$required}].");
            }
        }

        return new self(
            verb: $item['verb'],
            publishedAt: $item['published_at'],
            actor: $item['actor'] ?? null,
            object: $item['object'] ?? null,
            target: $item['target'] ?? null,
            context: $item['context'] ?? null,
            origin: $item['origin'] ?? null,
            result: $item['result'] ?? null,
            instrument: $item['instrument'] ?? null,
            data: $item['data'] ?? [],
            body: $item['body'] ?? null,
            id: isset($item['id']) ? (string) $item['id'] : null,
            startsAt: $item['starts_at'] ?? null,
            endsAt: $item['ends_at'] ?? null,
        );
    }

    /**
     * The unsaved activity this item reads as, its roles' snapshots built in
     * memory. Nothing is written.
     *
     * @internal
     */
    public function toActivity(int $key, string $uid): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        /** @var Activity $activity */
        $activity = new $model;
        $activity->forceFill([
            'id' => $key,
            'uid' => $uid,
            'verb' => $this->verb,
            'data' => $this->data === [] ? null : $this->data,
            'published_at' => $this->publishedAt,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ]);

        foreach (ActivityRoles::STORED as $role) {
            [$type, $id, $snapshot, $live] = $this->entity($role, $this->{$role});

            $activity->forceFill(["{$role}_type" => $type, "{$role}_id" => $id]);
            $activity->setRelation('cached'.ucfirst($role), $snapshot);
            $activity->setRelation($role, $live);
        }

        return $activity;
    }

    /** The id this item reads under when it names none: the same item, the same id. */
    public function identity(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $roles = [];

        foreach (ActivityRoles::STORED as $role) {
            $roles[$role] = $this->roleIdentity($this->{$role});
        }

        return substr(hash('sha256', json_encode([
            $this->verb, Chronology::stamp($this->publishedAt), $roles, $this->data,
        ], JSON_THROW_ON_ERROR)), 0, 26);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $value
     * @return array{string|null, string|null}|null
     */
    protected function roleIdentity(Model|string|array|null $value): ?array
    {
        return match (true) {
            $value === null => null,
            $value instanceof Model => [$value->getMorphClass(), MorphKeyType::value($value->getKey())],
            is_string($value) => [self::partyAlias(), self::partyKey($value)],
            default => [(string) $value['type'], (string) ($value['id'] ?? $value['label'])],
        };
    }

    /**
     * [type, id, snapshot, live model] for one role.
     *
     * @param  Model|string|array<string, mixed>|null  $value
     * @return array{string|null, string|null, Snapshot|null, Model|null}
     */
    protected function entity(string $role, Model|string|array|null $value): array
    {
        if ($value === null) {
            return [null, null, null, null];
        }

        $body = $role === 'object' ? $this->body : null;

        if ($value instanceof Model) {
            $snapshot = app(Feedables::class)->isFeedable($value) ? (new SnapshotEntity)->make($value) : null;

            if ($body !== null) {
                $snapshot ??= self::snapshot($value->getMorphClass(), MorphKeyType::value($value->getKey()), null, [], null);
                $snapshot->body = [...($snapshot->body ?? []), ...FeedEntity::make(body: $body)->body];
            }

            return [$value->getMorphClass(), MorphKeyType::value($value->getKey()), $snapshot, $value];
        }

        if (is_string($value)) {
            $key = self::partyKey($value);
            $snapshot = self::snapshot(self::partyAlias(), $key, $value, ['key' => $key, 'type' => ObjectType::Service->value], null);

            if ($body !== null) {
                $snapshot->body = FeedEntity::make(body: $body)->body;
            }

            return [self::partyAlias(), $key, $snapshot, null];
        }

        $id = (string) ($value['id'] ?? $value['label']);
        $entity = FeedEntity::make(label: $value['label'], data: $value['data'] ?? [], body: $value['body'] ?? null)->body($body);

        return [
            (string) $value['type'],
            $id,
            self::snapshot((string) $value['type'], $id, $entity->label, $entity->data, $entity->body === [] ? null : $entity->body, $value['url'] ?? null),
            null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $body
     */
    protected static function snapshot(string $type, ?string $id, ?string $label, array $data, ?array $body, ?string $url = null): Snapshot
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);

        return (new $model)->forceFill([
            'model_type' => $type,
            'model_id' => $id,
            'label' => $label,
            'data' => $data,
            'body' => $body,
            'meta' => $url === null ? [] : ['url' => $url],
        ]);
    }

    /** @param  array<string, mixed>  $entity */
    protected static function assertEntity(string $role, array $entity): void
    {
        InlineEntity::assert($role, $entity);
    }

    /** The key a party name is filed under, as Party::make() files it. */
    public static function partyKey(string $name): string
    {
        return Str::slug($name);
    }

    public static function partyAlias(): string
    {
        return (string) config('storyfeed.morph_alias', (new Party)->getMorphClass());
    }
}
