<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;
use InvalidArgumentException;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Exceptions\IncompleteFeedValue;

/**
 * The base a body type extends, after Laravel's `JsonResource`: the plumbing
 * every body repeated, written once.
 *
 *     class Shipment extends FeedBody
 *     {
 *         use HasTitle;
 *
 *         protected ?string $carrier = null;
 *
 *         protected function __construct(?string $carrier = null, ?string $title = null)
 *         {
 *             $this->carrier($carrier)->title($title);
 *         }
 *
 *         public function carrier(?string $carrier): static { … }
 *
 *         public static function bodyType(): string
 *         {
 *             return 'Acme/Shipment';
 *         }
 *
 *         protected function body(): array
 *         {
 *             return ['carrier' => $this->required($this->carrier, 'carrier'), 'title' => $this->title];
 *         }
 *
 *         protected static function defaults(): array
 *         {
 *             return ['title' => null];
 *         }
 *     }
 *
 * {@see Contracts\FeedBody} stays the contract, as `Contracts\Mail\Mailable`
 * does beside `Mail\Mailable`: a body that cannot extend this class, such as a
 * Data object, implements the interface instead.
 *
 * ## `toPayload()` is final
 *
 * So every body writes its envelope — `$body`, `$v`, and `$fallback` and
 * `$maxHeight` when it has them — the same way, and a body type writes only its own fields, in
 * {@see body()}. That is why this is a base class and not a trait.
 *
 * ## A body's payload reads as the body it renders
 *
 * A setting equal to its default is left out of the payload. A body declares
 * those defaults in {@see defaults()}; `toPayload()` drops any field that
 * still holds one, and {@see upgrade()} fills them back in at read time, so
 * the stored row stays small and the renderer still gets every key. A field a
 * reader expects to see, such as a `KeyValue` row's `value` or a `Prose`'s
 * `content`, has no default and is always written.
 *
 * A body type that slims a shape it used to write in full bumps its
 * `version()`, so the rows already stored keep reading as they did.
 *
 * ## A body sets its maximum height
 *
 *     Prose::markdown($notes)->maxHeight('none');   // show it all
 *     Table::make()->maxHeight('16rem');            // cap this one lower
 *
 * `maxHeight()` takes a CSS length or `none`, after Filament's `->maxHeight()`,
 * and writes `$maxHeight`. The body states its intent and a renderer draws it:
 * what happens past the height, an inner scroll or a "Show more", is the
 * renderer's to decide.
 */
abstract class FeedBody implements Contracts\FeedBody
{
    use Conditionable;
    use HasPayload;
    use Tappable;

    protected ?string $fallback = null;

    protected ?string $maxHeight = null;

    /**
     * Start a body. The arguments are the body type's constructor's, so named
     * arguments reach the same body as the chain: `KeyValue::make(title: 'Order')`.
     */
    public static function make(mixed ...$arguments): static
    {
        // @phpstan-ignore new.static (each body type's constructor is its make() signature, as JsonResource's is)
        return new static(...$arguments);
    }

    public static function version(): int
    {
        return 1;
    }

    /**
     * Fills in the {@see defaults()} a slim payload left out. A body type
     * whose shape changed between versions overrides this.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        return [...static::defaults(), ...$payload];
    }

    /**
     * One short line of plain text a renderer draws when it cannot draw this
     * body's type: an app type a renderer does not know, or a type whose
     * package is not installed. Without one, such a renderer draws nothing.
     */
    public function fallback(?string $fallback): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * How tall this body may get before a renderer shortens it: a CSS length
     * such as `16rem` or `320px`, or `none` to show it all. Null clears it, and
     * leaves the height to the renderer.
     *
     * @throws InvalidArgumentException when it is neither a length nor `none`
     */
    public function maxHeight(?string $height): static
    {
        if ($height !== null && $height !== 'none' && preg_match('/^(0|\d*\.?\d+(px|rem|em|ex|ch|lh|rlh|%|vh|svh|lvh|dvh|vw|svw|lvw|dvw|vmin|vmax|cm|mm|q|in|pt|pc))$/i', $height) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s::maxHeight() takes a CSS length such as `16rem` or `320px`, or `none`; `%s` given.',
                class_basename(static::class),
                $height,
            ));
        }

        $this->maxHeight = $height;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    final public function toPayload(): array
    {
        $defaults = static::defaults();
        $fallback = $this->fallback ?? $this->defaultFallback();

        return [
            self::KEY => static::bodyType(),
            self::VERSION => static::version(),
            ...($fallback === null || $fallback === '' ? [] : [self::FALLBACK => $fallback]),
            ...($this->maxHeight === null ? [] : [self::MAX_HEIGHT => $this->maxHeight]),
            ...array_filter(
                $this->body(),
                fn (mixed $value, string $key): bool => ! array_key_exists($key, $defaults) || $value !== $defaults[$key],
                ARRAY_FILTER_USE_BOTH,
            ),
        ];
    }

    /**
     * This body type's own fields, every one of them; {@see toPayload()}
     * adds the envelope and leaves out the defaults.
     *
     * @return array<string, mixed>
     */
    abstract protected function body(): array;

    /**
     * Each setting's default, by key. A field holding its default is left out
     * of the payload, and {@see upgrade()} puts it back.
     *
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [];
    }

    /**
     * The fallback line when none was given, such as a table's title.
     */
    protected function defaultFallback(): ?string
    {
        return null;
    }

    /**
     * A value this body cannot do without, checked when the body is used.
     *
     * @template TValue
     *
     * @param  TValue|null  $value
     * @return TValue
     *
     * @throws IncompleteFeedValue naming the method that sets it
     */
    protected function required(mixed $value, string $method): mixed
    {
        return $value ?? throw IncompleteFeedValue::missing(static::class, $method);
    }

    /**
     * A link whose label this body draws, as its payload: `{label, href,
     * modal, attributes}`. The label is optional on a {@see FeedLink} and
     * required here, so a missing one throws, naming `label()`.
     *
     * @return array{label: string, href: string|null, modal: bool, attributes: array<string, mixed>}
     */
    protected static function labelledLink(FeedLink $link): array
    {
        $payload = $link->toPayload();

        if ($payload['label'] === null) {
            throw IncompleteFeedValue::missing(FeedLink::class, 'label');
        }

        return $payload;
    }

    /**
     * A stored link with its label, upgraded to the full shape, or null when
     * the value is not one.
     *
     * @return array{label: string, href: string|null, modal: bool, attributes: array<string, mixed>}|null
     */
    protected static function storedLabelledLink(mixed $value): ?array
    {
        $link = FeedLink::from($value);

        return $link?->label === null ? null : self::labelledLink($link);
    }
}
