<?php

namespace Storyfeed\Sources;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
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
use Stringable;

/**
 * One entry of a feed that is not stored: what happened or what is true,
 * who and what took part, and optionally when. Composed fluently:
 *
 *   Storyfeed::compose()
 *       ->add(fn (Entry $entry) => $entry->by($customer)->action('place', $order)->publishedAt($order->placed_at))
 *       ->add(fn (Entry $entry) => $entry->headline(':actor made this!', ['actor' => $user]));
 *
 * …made with named arguments, for a source's items():
 *
 *   Entry::make('release', '2026-10-20 09:00', actor: 'Storyfeed', object: $release);
 *
 * …or as an array, the same keys:
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
 * A role (actor, object, target, context, origin, result, instrument,
 * location, generator) is a model, a party name, or an entity with no model
 * behind it: an array with a `type` and a `label`, and optionally a `url`,
 * an `id`, `data` and a `body`. An entity's `id` defaults to its label. The
 * role methods are the recording vocabulary's: `by()`, `on()`, `at()` and
 * the rest set the roles they set on `Storyfeed::activity()`.
 *
 * `body` is the object's body, as on a Feedable's `toFeed()`: one body,
 * several, or a line of text. `id` is the payload's activity id; without
 * one, an id is derived from the entry, so it stays the same from one read
 * to the next.
 *
 * The verb and the date are optional. An entry with no verb says only its
 * headline; an entry with no date is dateless, and its node's
 * `published_at` is null.
 */
final class Entry
{
    /** The keys an array entry may carry; anything else is an error. */
    protected const KEYS = ['verb', 'published_at', 'starts_at', 'ends_at', 'data', 'body', 'id', ...ActivityRoles::STORED];

    /** The keys an entity array may carry. */
    protected const ENTITY_KEYS = InlineEntity::KEYS;

    /**
     * The attribute an in-memory activity carries its entry's own headline
     * under. Activities read from the database never have it.
     *
     * @internal
     */
    public const HEADLINE = 'entry_headline';

    /** Null for an entry with no verb: one that only says its headline. */
    public private(set) ?string $verb = null;

    /** Null for a dateless entry: something true now rather than an event. */
    public private(set) ?Carbon $publishedAt = null;

    public private(set) ?Carbon $startsAt = null;

    public private(set) ?Carbon $endsAt = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $actor = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $object = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $target = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $context = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $origin = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $result = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $instrument = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $location = null;

    /** @var Model|string|array<string, mixed>|null */
    public private(set) Model|string|array|null $generator = null;

    /** @var array<string, mixed> */
    public private(set) array $data = [];

    /** @var string|FeedBody|iterable<mixed>|null */
    public private(set) string|FeedBody|iterable|null $body = null;

    public private(set) ?string $id = null;

    /** The entry's own headline template, which wins over the feed file's. */
    public private(set) ?string $headline = null;

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
     * @param  Model|string|array<string, mixed>|null  $location
     * @param  Model|string|array<string, mixed>|null  $generator
     */
    public static function make(
        string|FeedVerb|BackedEnum|null $verb = null,
        DateTimeInterface|string|null $publishedAt = null,
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
        Model|string|array|null $location = null,
        Model|string|array|null $generator = null,
    ): self {
        $entry = new self;

        if ($verb !== null) {
            $entry->verb($verb);
        }

        foreach (compact(ActivityRoles::STORED) as $role => $value) {
            $entry->role($role, $value);
        }

        $entry->data = $data;
        $entry->body = $body;
        $entry->id = $id;
        $entry->publishedAt = self::date($publishedAt);
        $entry->startsAt = self::date($startsAt);
        $entry->endsAt = self::date($endsAt);

        return $entry->validated();
    }

    /**
     * An entry from its array form. A missing verb or published_at, or a key
     * this class does not know, throws.
     *
     * @param  self|array<string, mixed>  $entry
     */
    public static function from(self|array $entry): self
    {
        if ($entry instanceof self) {
            return $entry->validated();
        }

        if (($unknown = array_diff(array_keys($entry), self::KEYS)) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown entry key [%s]. Entries take: %s.', implode(', ', $unknown), implode(', ', self::KEYS),
            ));
        }

        foreach (['verb', 'published_at'] as $required) {
            if (! isset($entry[$required])) {
                throw new InvalidArgumentException("An entry needs [{$required}].");
            }
        }

        return self::make(
            verb: $entry['verb'],
            publishedAt: $entry['published_at'],
            actor: $entry['actor'] ?? null,
            object: $entry['object'] ?? null,
            target: $entry['target'] ?? null,
            context: $entry['context'] ?? null,
            origin: $entry['origin'] ?? null,
            result: $entry['result'] ?? null,
            instrument: $entry['instrument'] ?? null,
            data: $entry['data'] ?? [],
            body: $entry['body'] ?? null,
            id: isset($entry['id']) ? (string) $entry['id'] : null,
            startsAt: $entry['starts_at'] ?? null,
            endsAt: $entry['ends_at'] ?? null,
            location: $entry['location'] ?? null,
            generator: $entry['generator'] ?? null,
        );
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $object
     */
    public function verb(string|FeedVerb|BackedEnum $verb, Model|string|array|null $object = null): static
    {
        $verb = match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => $verb,
        };

        if ($verb === '' || str_contains($verb, '.')) {
            throw new InvalidArgumentException("Entry verb [{$verb}] must be a non-empty verb without a dot.");
        }

        $this->verb = $verb;

        return $object === null ? $this : $this->object($object);
    }

    /**
     * The action the actor took: `->by($user)->action('upload', $document)`.
     * An alias for `verb()`, as on `Storyfeed::activity()`.
     *
     * @param  Model|string|array<string, mixed>|null  $object
     */
    public function action(string|FeedVerb|BackedEnum $verb, Model|string|array|null $object = null): static
    {
        return $this->verb($verb, $object);
    }

    /**
     * The entry's own headline, as `__()` takes a line: role tokens stay
     * tokens, so linked names stay links, and the replacements fill them.
     *
     *   ->headline('Opened the doors to the new kitchen')
     *   ->headline(':actor shipped :object to :target', ['actor' => $team, 'object' => $release, 'target' => $customer])
     *   ->headline(':object, :tagline', ['object' => $project, 'tagline' => $project->tagline])
     *
     * A replacement keyed by a role sets that role, as its method would:
     * `['actor' => $user]` is `->by($user)`. Any other key is replaced in
     * the text now, as `__()` replaces it (`:key`, `:Key`, `:KEY`), so it
     * reaches the payload as plain text. A model under a key that is not a
     * role throws: a model is a participant, and a participant is a role.
     * The headline wins over the feed file's wording for the verb.
     *
     * @param  array<string, mixed>  $replace
     */
    public function headline(string $headline, array $replace = []): static
    {
        $text = [];

        foreach ($replace as $key => $value) {
            if (in_array($key, ActivityRoles::STORED, true)) {
                if (! $value instanceof Model && ! is_string($value) && ! is_array($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'The [:%s] replacement fills the [%s] role, which takes a model, a party name or an entity; %s given.', $key, $key, get_debug_type($value),
                    ));
                }

                $this->role($key, $value);

                continue;
            }

            $text[$key] = self::replacement((string) $key, $value);
        }

        $this->headline = self::replace($headline, $text);

        return $this;
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function actor(Model|string|array|null $model = null): static
    {
        return $this->role('actor', $model);
    }

    /**
     * Actor, as a byline: `->by($user)->action('upload', $document)`.
     *
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function by(Model|string|array|null $model = null): static
    {
        return $this->actor($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function object(Model|string|array|null $model = null): static
    {
        return $this->role('object', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function target(Model|string|array|null $model = null): static
    {
        return $this->role('target', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function context(Model|string|array|null $model = null): static
    {
        return $this->role('context', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function origin(Model|string|array|null $model = null): static
    {
        return $this->role('origin', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function result(Model|string|array|null $model = null): static
    {
        return $this->role('result', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function instrument(Model|string|array|null $model = null): static
    {
        return $this->role('instrument', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function location(Model|string|array|null $model = null): static
    {
        return $this->role('location', $model);
    }

    /**
     * Location, as a place: `->action('unveil', $storyfeed)->at($talk)`.
     * As on `Storyfeed::activity()`; the date is `publishedAt()`.
     *
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function at(Model|string|array|null $model = null): static
    {
        return $this->location($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function generator(Model|string|array|null $model = null): static
    {
        return $this->role('generator', $model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function using(Model|string|array|null $model = null): static
    {
        return $this->instrument($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function resulting(Model|string|array|null $model = null): static
    {
        return $this->result($model);
    }

    /**
     * Target aliases, as on `Storyfeed::activity()`: each sets `target` and
     * nothing else, so a line reads as the sentence it composes. There is no
     * `from()`: on an entry, `Entry::from()` reads the array form.
     *
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function in(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function to(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function for(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function on(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function with(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $model  a model, a party name, or an entity with no model behind it
     */
    public function into(Model|string|array|null $model = null): static
    {
        return $this->target($model);
    }

    /**
     * The entry's data map.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public function data(array|Arrayable $data): static
    {
        $this->data = $data instanceof Arrayable ? $data->toArray() : $data;

        return $this;
    }

    /**
     * The object's body, as on a Feedable's `toFeed()`: one body, several,
     * or a line of text. An entry with a body needs an object.
     *
     * @param  string|FeedBody|iterable<mixed>|null  $body
     */
    public function body(string|FeedBody|iterable|null $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** The entry's payload id, in place of the one derived from it. */
    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /** When it happened. Without one, the entry is dateless. */
    public function publishedAt(DateTimeInterface|string|null $date): static
    {
        $this->publishedAt = self::date($date);

        return $this;
    }

    /** When what the entry describes began or begins, beside `published_at`. */
    public function startsAt(DateTimeInterface|string|null $date): static
    {
        $this->startsAt = self::date($date);

        return $this;
    }

    /** When what the entry describes ended or ends, beside `published_at`. */
    public function endsAt(DateTimeInterface|string|null $date): static
    {
        $this->endsAt = self::date($date);

        return $this;
    }

    /**
     * The entry, checked as a whole: what no one setter can see alone.
     *
     * @internal
     */
    public function validated(): self
    {
        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt->lt($this->startsAt)) {
            throw new InvalidArgumentException("An activity cannot end [{$this->endsAt->toIso8601String()}] before it starts [{$this->startsAt->toIso8601String()}].");
        }

        if ($this->body !== null && $this->object === null) {
            throw new InvalidArgumentException('An entry with a body needs an object: the body is the object\'s.');
        }

        if ($this->headline !== null) {
            $unfilled = array_filter(self::roleTokens($this->headline), fn (string $role) => $this->{$role} === null);

            if ($unfilled !== []) {
                throw new InvalidArgumentException(sprintf(
                    'The headline [%s] names [:%s], but the entry has no %s. Fill the role, or wrap the words in [ ] to drop them when it is empty.',
                    $this->headline, implode(', :', $unfilled), implode(' or ', $unfilled),
                ));
            }
        }

        return $this;
    }

    /**
     * @param  Model|string|array<string, mixed>|null  $value
     */
    protected function role(string $role, Model|string|array|null $value): static
    {
        if (is_array($value)) {
            InlineEntity::assert($role, $value);
        }

        // Nothing is stored for an entry, so a model is read only through
        // its toFeed(): one that has none would draw a role with no label.
        if ($value instanceof Model && ! app(Feedables::class)->isFeedable($value)) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] role is a %s, which is not Feedable, so it would read with no label. '
                .'Pass an entity array ([\'type\' => …, \'label\' => …]), or make the model Feedable.',
                $role, $value::class,
            ));
        }

        $this->{$role} = $value;

        return $this;
    }

    protected static function date(DateTimeInterface|string|null $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date);
    }

    /** A non-role replacement as text; a participant is refused. */
    protected static function replacement(string $key, mixed $value): string
    {
        return match (true) {
            $value instanceof Model => throw new InvalidArgumentException(sprintf(
                'The [:%s] replacement is a %s model, and a model is a participant: pass it under a role (%s), or pass a string.',
                $key, $value::class, implode(', ', ActivityRoles::STORED),
            )),
            $value === null => '',
            is_string($value), is_int($value), is_float($value), $value instanceof Stringable => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            default => throw new InvalidArgumentException(sprintf(
                'The [:%s] replacement must be text, %s given. Only a role takes a model or an entity.', $key, get_debug_type($value),
            )),
        };
    }

    /**
     * `__()`'s replacement, exactly: `:key`, `:Key` and `:KEY`.
     *
     * @param  array<string, string>  $replace
     */
    protected static function replace(string $line, array $replace): string
    {
        $shouldReplace = [];

        foreach ($replace as $key => $value) {
            $shouldReplace[':'.Str::ucfirst($key)] = Str::ucfirst($value);
            $shouldReplace[':'.Str::upper($key)] = Str::upper($value);
            $shouldReplace[':'.$key] = $value;
        }

        return strtr($line, $shouldReplace);
    }

    /**
     * The roles a headline names outside its optional segments.
     *
     * @return list<string>
     */
    protected static function roleTokens(string $headline): array
    {
        preg_match_all('/:([a-z]+)/', (string) preg_replace('/\[[^\[\]]*\]/', '', $headline), $matches);

        $roles = [];

        foreach ($matches[1] as $token) {
            // `:actors` belongs to `actor`, as FeedHeadline reads it.
            $role = str_ends_with($token, 's') && in_array(substr($token, 0, -1), ActivityRoles::STORED, true) ? substr($token, 0, -1) : $token;

            if (in_array($role, ActivityRoles::STORED, true)) {
                $roles[$role] = $role;
            }
        }

        return array_values($roles);
    }

    /**
     * The unsaved activity this entry reads as, its roles' snapshots built in
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

        if ($this->headline !== null) {
            // Never stored: a source item is read in memory, and the presenter
            // reads this ahead of the feed file's wording.
            $activity->setAttribute(self::HEADLINE, $this->headline);
        }

        foreach (ActivityRoles::STORED as $role) {
            [$type, $id, $snapshot, $live] = $this->entity($role, $this->{$role});

            $activity->forceFill(["{$role}_type" => $type, "{$role}_id" => $id]);
            $activity->setRelation('cached'.ucfirst($role), $snapshot);
            $activity->setRelation($role, $live);
        }

        return $activity;
    }

    /** The id this entry reads under when it names none: the same item, the same id. */
    public function identity(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $roles = [];

        foreach (ActivityRoles::STORED as $role) {
            // A role added since ids were first derived joins only when
            // filled, so an entry that never names it keeps the id it had.
            if (in_array($role, ['location', 'generator'], true) && $this->{$role} === null) {
                continue;
            }

            $roles[$role] = $this->roleIdentity($this->{$role});
        }

        // Fields added since ids were first derived join only when filled,
        // so an entry that never sets them keeps the id it had.
        $extra = array_filter(['headline' => $this->headline], fn (?string $value) => $value !== null);

        return substr(hash('sha256', json_encode([
            $this->verb, $this->publishedAt === null ? null : Chronology::stamp($this->publishedAt), $roles, $this->data, ...($extra === [] ? [] : [$extra]),
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
