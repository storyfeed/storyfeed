<?php

namespace Storyfeed\Body;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\FeedLink;
use Stringable;

/**
 * Several things, each one a name and optionally somewhere to go.
 *
 *     body: ItemList::make(
 *         $order->lines->map(fn (OrderLine $line) => FeedLink::make($line->item->name, $line->item->url)),
 *         title: 'Three dishes went out',
 *     )
 *
 * ## The line between this and KeyValue
 *
 * `KeyValue` answers "what are the facts about this thing" — every row is a
 * pair, and the left side labels the right. This answers "what are these
 * things" — every item is one thing, and there is no second column to fill.
 * A ticket is the first; a list of dishes is the second, and reaching for the
 * wrong one shows immediately, because half the payload ends up empty or a
 * quantity gets baked into a label to fill it.
 *
 * ## `ordered` is a fact, not a bullet style
 *
 * Whether the sequence carries meaning is something only the app knows, which
 * is why HTML made `ol` and `ul` separate elements and why AS2 separates
 * `Collection` from `OrderedCollection`. Numbering is a renderer's conclusion
 * from the fact, not the fact.
 *
 * It also exists defensively. Without it an app that needs ordered items
 * writes "1. Preheat" into the item itself, and the number becomes text no
 * renderer can align, count or renumber — the same defect as a quantity baked
 * into a `KeyValue` key.
 *
 * ## `totalItems` is how many there are; `items` is how many you sent
 *
 * The two differ whenever a list is long, and the difference is the app's to
 * state: the server decides what is worth sending, on cost. HOW MANY OF WHAT
 * ARRIVED TO SHOW is not here and never will be — a collapse threshold depends
 * on the viewport, the density and whether this row is in a rail or a page,
 * none of which a server knows. A renderer holding twenty items shows what
 * fits and reveals the rest; there is nothing to say about that in a payload.
 *
 * `more` is where the rest lives when they were not sent. A count with nowhere
 * to go is a tease, so the two travel together or the count stands alone as a
 * plain fact.
 */
class ItemList implements FeedBody
{
    use HasPayload;

    /**
     * @param  list<string|array{label: string, href: string|null}>  $items
     */
    final protected function __construct(
        private readonly array $items,
        private readonly bool $ordered = false,
        private readonly ?string $title = null,
        private readonly ?int $totalItems = null,
        private readonly ?FeedLink $more = null,
    ) {}

    /**
     * @param  iterable<mixed>  $items  strings, or `FeedLink`s where an item leads somewhere
     * @param  string|null  $title  a line above the items, when the headline does not already say it
     * @param  int|null  $totalItems  how many exist, when that is more than were sent
     * @param  FeedLink|null  $more  where the rest live — a label and an href, never a bare url
     */
    public static function make(
        iterable $items,
        ?string $title = null,
        ?int $totalItems = null,
        ?FeedLink $more = null,
    ): static {
        return new static(self::normalize($items), false, $title, $totalItems, $more);
    }

    /**
     * The same list, where the sequence is part of what it says.
     *
     * @param  iterable<mixed>  $items
     */
    public static function ordered(
        iterable $items,
        ?string $title = null,
        ?int $totalItems = null,
        ?FeedLink $more = null,
    ): static {
        return new static(self::normalize($items), true, $title, $totalItems, $more);
    }

    /**
     * A STRING STAYS A STRING and a link stays a link, exactly as
     * {@see FeedLink::from()} insists and {@see MediaObject} already stores
     * them: text that leads nowhere and text that leads somewhere are
     * different facts, and flattening them would make every plain item
     * clickable the day its field widened.
     *
     * Anything that is neither is dropped rather than coerced — the same rule
     * an unrecognised body type gets, for the same reason.
     *
     * @param  iterable<mixed>  $items
     * @return list<string|array{label: string, href: string|null}>
     */
    private static function normalize(iterable $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if ($item instanceof FeedLink) {
                $normalized[] = $item->toPayload();

                continue;
            }

            if (is_string($item)) {
                if ($item !== '') {
                    $normalized[] = $item;
                }

                continue;
            }

            if ($item instanceof Stringable || (is_object($item) && method_exists($item, '__toString'))) {
                $text = (string) $item;

                if ($text !== '') {
                    $normalized[] = $text;
                }

                continue;
            }

            $link = FeedLink::from($item);

            if ($link !== null) {
                $normalized[] = $link->toPayload();
            }
        }

        return $normalized;
    }

    /**
     * `Storyfeed/Body/ItemList` — the VOCABULARY'S name, not a package's.
     *
     * `ItemList` is schema.org's type for exactly this, so the name is a
     * transcription rather than a coinage. `List` was not available: it is a
     * PHP reserved word and will not compile as a class name.
     */
    public static function name(): string
    {
        return 'Storyfeed/Body/ItemList';
    }

    public static function version(): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $title = $payload['title'] ?? null;
        $total = $payload['totalItems'] ?? null;
        $more = FeedLink::from($payload['more'] ?? null);

        return [
            'title' => is_string($title) ? $title : null,
            'ordered' => (bool) ($payload['ordered'] ?? false),
            'items' => array_values(array_filter(
                $items,
                fn (mixed $item): bool => is_string($item) || FeedLink::from($item) !== null,
            )),
            'totalItems' => is_int($total) ? $total : null,
            'more' => $more?->toPayload(),
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, title: string|null, ordered: bool, items: list<string|array{label: string, href: string|null}>, totalItems: int|null, more: array<string, mixed>|null}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'title' => $this->title,
            'ordered' => $this->ordered,
            'items' => $this->items,
            'totalItems' => $this->totalItems,
            'more' => $this->more?->toPayload(),
        ];
    }
}
