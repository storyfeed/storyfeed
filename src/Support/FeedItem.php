<?php

namespace Storyfeed\Support;

use ArrayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;
use JsonSerializable;
use Storyfeed\Concerns\ReadsPayloadArray;

/**
 * One item of a feed page, an activity or a group, read fluently. Iterating
 * a FeedPage yields them, so a Blade loop over the page reads
 * `{{ $item->headline() }}` and `$item->actor()->label()`.
 *
 * The Laravel analogue is `Illuminate\Support\Uri`: it wraps one value,
 * answers a named accessor per part, and hands structured parts back as
 * readers of their own (`headline()`, `actor()`, `children()`).
 *
 * A reader, never a writer. It wraps the item exactly as the payload has it
 * (docs/payload.md), reads it as array access too (`$item['verb']`), and
 * `toArray()` / JSON hand the same array back. Nothing here changes the
 * contract; a key the reader has no accessor for is still `get('key')`.
 *
 * A digest row's phrases are read with this class too: a phrase has a
 * verb, a count, a headline and samples, but no kind and no time.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
final class FeedItem implements Arrayable, ArrayAccess, JsonSerializable
{
    use Conditionable, Dumpable, Macroable, ReadsPayloadArray, Tappable;

    /**
     * @param  array<string, mixed>  $payload  an item of a feed page
     */
    public function __construct(protected array $payload) {}

    /**
     * Read an item of a feed page.
     *
     * @param  array<string, mixed>|FeedItem  $item
     */
    public static function of(array|FeedItem $item): self
    {
        return new self($item instanceof FeedItem ? $item->toArray() : $item);
    }

    /** `activity` or `group`; null for a digest phrase. */
    public function kind(): ?string
    {
        return $this->string('kind');
    }

    public function isActivity(): bool
    {
        return $this->kind() === 'activity';
    }

    public function isGroup(): bool
    {
        return $this->kind() === 'group';
    }

    /** Whether this is a digest row: a group with `axis: "summary"`. */
    public function isDigest(): bool
    {
        return $this->isGroup() && $this->axis() === 'summary';
    }

    /** The item's public id. */
    public function id(): ?string
    {
        return $this->string('id');
    }

    /** The verb, or null for a digest row that spans verbs. */
    public function verb(): ?string
    {
        return $this->string('verb');
    }

    /** When it happened; for a group, its newest member. */
    public function publishedAt(): ?CarbonImmutable
    {
        $at = $this->string('published_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }

    /** The sentence, read from `headline_template` or `headline`. */
    public function headline(): Headline
    {
        return new Headline($this, $this->string('headline_template'), $this->string('headline'));
    }

    /**
     * The verb's own reading once the activity is redundant, or null when
     * it is not redundant or the verb declares none.
     */
    public function missingHeadline(): ?Headline
    {
        $template = $this->string('missing_headline_template');
        $text = $this->string('missing_headline');

        return $template === null && $text === null ? null : new Headline($this, $template, $text);
    }

    /** The verb's glyph token, such as `bi-truck`. */
    public function glyph(): ?string
    {
        return $this->string('glyph');
    }

    /** The glyph's intent, your app's word, such as `success`. */
    public function intent(): ?string
    {
        return $this->string('glyph_intent');
    }

    public function actor(): ?Entity
    {
        return $this->entity('actor');
    }

    public function object(): ?Entity
    {
        return $this->entity('object');
    }

    public function target(): ?Entity
    {
        return $this->entity('target');
    }

    public function context(): ?Entity
    {
        return $this->entity('context');
    }

    public function origin(): ?Entity
    {
        return $this->entity('origin');
    }

    public function result(): ?Entity
    {
        return $this->entity('result');
    }

    public function instrument(): ?Entity
    {
        return $this->entity('instrument');
    }

    /**
     * The entity in one role. On a group it is set only when the group's
     * axis pins the role; otherwise read the plural (`actors()`).
     */
    public function entity(string $role): ?Entity
    {
        $role = self::singular($role);
        $entity = $this->payload[$role] ?? null;

        return is_array($entity) ? new Entity($entity, $role) : null;
    }

    /** @return Collection<int, Entity> */
    public function actors(): Collection
    {
        return $this->entities('actor');
    }

    /** @return Collection<int, Entity> */
    public function objects(): Collection
    {
        return $this->entities('object');
    }

    /** @return Collection<int, Entity> */
    public function targets(): Collection
    {
        return $this->entities('target');
    }

    /** @return Collection<int, Entity> */
    public function contexts(): Collection
    {
        return $this->entities('context');
    }

    /** @return Collection<int, Entity> */
    public function origins(): Collection
    {
        return $this->entities('origin');
    }

    /** @return Collection<int, Entity> */
    public function results(): Collection
    {
        return $this->entities('result');
    }

    /** @return Collection<int, Entity> */
    public function instruments(): Collection
    {
        return $this->entities('instrument');
    }

    /**
     * The entities in one role. A group's are its `sample`, up to a few of
     * `distinct()`; an activity's is its one entity, or none.
     *
     * @return Collection<int, Entity>
     */
    public function entities(string $role): Collection
    {
        $role = self::singular($role);

        if (! array_key_exists('sample', $this->payload)) {
            return collect([$this->entity($role)])->filter()->values();
        }

        $sample = $this->payload['sample'][$role.'s'] ?? [];

        return collect(is_array($sample) ? $sample : [])
            ->filter(fn (mixed $value): bool => is_array($value))
            ->map(fn (array $entity): Entity => new Entity($entity, $role))
            ->values();
    }

    /**
     * How many distinct entities hold a role: a group's true total, of
     * which `entities()` names a few. An activity's is 1 or 0.
     */
    public function distinct(string $role): int
    {
        $role = self::singular($role);

        if (! array_key_exists('distinct', $this->payload)) {
            return $this->entity($role) === null ? 0 : 1;
        }

        $count = $this->payload['distinct'][$role.'s'] ?? 0;

        return is_int($count) ? $count : 0;
    }

    /**
     * The activity-level data, as the recording call passed it.
     *
     * @return Fluent<string, mixed>
     */
    public function data(): Fluent
    {
        return new Fluent(is_array($this->payload['data'] ?? null) ? $this->payload['data'] : []);
    }

    /**
     * The utterance this activity quotes (`text`, `by`, `kind`, `replies`, `truncated`), or null.
     *
     * @return Fluent<string, mixed>|null
     */
    public function thread(): ?Fluent
    {
        return is_array($this->payload['thread'] ?? null) ? new Fluent($this->payload['thread']) : null;
    }

    /**
     * The roles holding a tombstone, in role order.
     *
     * @return list<string>
     */
    public function tombstoned(): array
    {
        return array_values(array_filter((array) ($this->payload['tombstoned'] ?? []), is_string(...)));
    }

    /** Whether what the activity is about has since been deleted. */
    public function isRedundant(): bool
    {
        return ($this->payload['redundant'] ?? false) === true;
    }

    /** How many activities: a group's members, and 1 for an activity. */
    public function count(): int
    {
        $count = $this->payload['count'] ?? 1;

        return is_int($count) ? $count : 1;
    }

    /**
     * A group's member activities, newest first; possibly fewer than `count()`.
     *
     * @return Collection<int, FeedItem>
     */
    public function children(): Collection
    {
        return $this->items('children');
    }

    /** Whether the group has more members than `children()` holds. */
    public function childrenTruncated(): bool
    {
        return ($this->payload['children_truncated'] ?? false) === true;
    }

    /** A group's winning axis: `repeat`, `actors`, `targets`, `object`, `summary`, … */
    public function axis(): ?string
    {
        return $this->string('axis');
    }

    /** A digest row's calendar period: `hour`, `day`, `week` or `month`. */
    public function period(): ?string
    {
        return $this->string('period');
    }

    /**
     * A digest row's per-verb phrases, in the order each verb first occurred.
     *
     * @return Collection<int, FeedItem>
     */
    public function phrases(): Collection
    {
        return $this->items('phrases');
    }

    /** Whether the digest row has more phrases than `phrases()` holds. */
    public function phrasesTruncated(): bool
    {
        return ($this->payload['phrases_truncated'] ?? false) === true;
    }

    /** @return Collection<int, FeedItem> */
    protected function items(string $key): Collection
    {
        return collect(is_array($this->payload[$key] ?? null) ? $this->payload[$key] : [])
            ->filter(fn (mixed $value): bool => is_array($value))
            ->map(fn (array $item): FeedItem => new self($item))
            ->values();
    }

    protected function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** `actors` → `actor`; a singular role unchanged. */
    protected static function singular(string $role): string
    {
        $singular = str_ends_with($role, 's') ? substr($role, 0, -1) : $role;

        return in_array($singular, ActivityRoles::PAYLOAD, true) ? $singular : $role;
    }
}
