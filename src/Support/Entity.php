<?php

namespace Storyfeed\Support;

use ArrayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Tappable;
use JsonSerializable;
use Storyfeed\Concerns\ReadsPayloadArray;
use Stringable;

/**
 * One entity object of a feed item (docs/payload.md, "Entity object"), read
 * fluently: `$item->actor()->label()`, `{{ $item->object() }}`.
 *
 * A reader, never a writer: the array it wraps is the payload's, unchanged,
 * and `toArray()` hands it back as it came.
 *
 * The role it was read from (`actor`, `object`, …) chooses its fallback
 * words when it has no label, so a degraded actor reads "Someone" and a
 * degraded object "Something". The words are the package's lang lines
 * (`storyfeed::feed.*`), translated in the reader's locale.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
final class Entity implements Arrayable, ArrayAccess, Htmlable, JsonSerializable, Stringable
{
    use Conditionable, Dumpable, ReadsPayloadArray, Tappable;

    /**
     * @param  array<string, mixed>  $payload  an entity object from the payload
     * @param  string|null  $role  the role it was read from, singular
     */
    public function __construct(
        protected array $payload,
        protected ?string $role = null,
    ) {}

    /**
     * Read an entity object.
     *
     * @param  array<string, mixed>|Entity  $entity
     */
    public static function of(array|Entity $entity, ?string $role = null): self
    {
        return new self($entity instanceof Entity ? $entity->toArray() : $entity, $role ?? ($entity instanceof Entity ? $entity->role() : null));
    }

    /** The role this entity was read from (`actor`, `object`, …), or null. */
    public function role(): ?string
    {
        return $this->role;
    }

    /** The morph alias: `order`, `storyfeed.party`, `storyfeed.tombstone`. */
    public function type(): ?string
    {
        return $this->string('type');
    }

    /** The entity's own key, as a string. */
    public function id(): ?string
    {
        return $this->string('id');
    }

    /** The snapshot label, or null when it has none. `toString()` supplies the fallback. */
    public function label(): ?string
    {
        return $this->string('label');
    }

    /** The link minted at read time, or null when the entity is not linkable. */
    public function url(): ?string
    {
        return $this->string('url');
    }

    /**
     * The link attributes the resolver set, such as `['target' => '_blank']`.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return is_array($this->payload['attributes'] ?? null) ? $this->payload['attributes'] : [];
    }

    /** Whether the resolver asked for the link to open as a modal. */
    public function isModal(): bool
    {
        return ($this->payload['modal'] ?? false) === true;
    }

    /**
     * The snapshot data, the app's own map.
     *
     * @return Fluent<string, mixed>
     */
    public function data(): Fluent
    {
        return new Fluent(is_array($this->payload['data'] ?? null) ? $this->payload['data'] : []);
    }

    /**
     * The typed image slots (`icon`, `image`, `preview`, `url`) and
     * `attachments`, or null when the entity has no media.
     *
     * @return Fluent<string, mixed>|null
     */
    public function media(): ?Fluent
    {
        return is_array($this->payload['media'] ?? null) ? new Fluent($this->payload['media']) : null;
    }

    /**
     * The entity's non-image resources, in the resolver's order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function attachments(): Collection
    {
        $attachments = $this->payload['media']['attachments'] ?? null;

        return collect(is_array($attachments) ? $attachments : [])->values();
    }

    /**
     * The entity's bodies, each an array naming its type in `$body`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bodies(): Collection
    {
        return collect(is_array($this->payload['body'] ?? null) ? $this->payload['body'] : [])->values();
    }

    /** The utterance, when the entity itself is a note or document. */
    public function content(): ?string
    {
        return $this->string('content');
    }

    /** The media type of `content()`, such as `text/markdown`. */
    public function mediaType(): ?string
    {
        return $this->string('mediaType');
    }

    /** The author IRI of `content()`. */
    public function attributedTo(): ?string
    {
        return $this->string('attributedTo');
    }

    /**
     * Whether the entity has no snapshot yet: no label, no link, and not a
     * tombstone. It reads with a neutral placeholder.
     */
    public function isDegraded(): bool
    {
        return $this->label() === null && ! $this->isTombstone();
    }

    /** Whether the model was deleted and this is what it left behind. */
    public function isTombstone(): bool
    {
        return is_array($this->payload['tombstone'] ?? null);
    }

    /** The deleted model's morph alias, for a tombstone. */
    public function formerType(): ?string
    {
        $type = $this->payload['tombstone']['formerType'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** When the model was deleted, for a tombstone, or null when nobody knows. */
    public function deletedAt(): ?CarbonImmutable
    {
        $deleted = $this->payload['tombstone']['deleted'] ?? null;

        return is_string($deleted) ? CarbonImmutable::parse($deleted) : null;
    }

    /**
     * What the entity reads as: its label, or the fallback words for a
     * tombstone ("a removed order") or a degraded entity ("Something").
     */
    public function toString(): string
    {
        if (($label = $this->label()) !== null) {
            return $label;
        }

        if ($this->isTombstone()) {
            return (string) __($this->role === 'actor' ? 'storyfeed::feed.former' : 'storyfeed::feed.removed', [
                'type' => self::noun($this->formerType()),
            ]);
        }

        return self::placeholder($this->role);
    }

    /**
     * The entity as HTML: a link when it has a `url`, carrying its link
     * attributes, and the escaped label otherwise.
     */
    public function toHtml(): string
    {
        $label = e($this->toString());

        if (($url = $this->url()) === null) {
            return $label;
        }

        $attributes = ['href' => $url, ...$this->attributes()];
        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= match (true) {
                $value === true => ' '.e($name),
                $value === false, $value === null, ! is_scalar($value) => '',
                default => ' '.e($name).'="'.e((string) $value).'"',
            };
        }

        return "<a{$html}>{$label}</a>";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * The words for a role with nothing to name: "Someone" for the actor,
     * "Something" for any other role.
     *
     * @internal
     */
    public static function placeholder(?string $role): string
    {
        return (string) __($role === 'actor' ? 'storyfeed::feed.someone' : 'storyfeed::feed.something');
    }

    /** A morph alias as a noun: `line_item` → "line item". */
    protected static function noun(?string $type): string
    {
        return $type === null ? (string) __('storyfeed::feed.item') : Str::lower(Str::headline(class_basename($type)));
    }

    protected function string(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
