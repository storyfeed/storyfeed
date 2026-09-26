<?php

namespace Storyfeed\Body;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Stringable;

/**
 * Labelled rows under a headline — the commonest body type, and the one two
 * consumers hand-wrote independently before it existed.
 *
 *     ->body(KeyValue::make()->items([
 *         'Address' => KeyValue::verbatim($fetch->ip),
 *         'Where the address resolved' => $fetch->geo?->describe(),
 *         'Looked automated' => $fetch->is_bot,
 *     ]))
 *
 * `items()` merges a map as `View::with()` does — a key already here takes
 * the later value in its place — and `items('Address', $ip)` sets one row.
 * A list of explicit `['key' => …, 'value' => …]` pairs appends instead,
 * which is how a key repeats.
 *
 * ## Why `KeyValue` and not `Details`
 *
 * A member cannot share a name with its own category. `detail` is the category
 * — it is already the name of the seam in the Filament adapter's
 * `detail_view` config, in `.sf-detail`, and in its README's "Rendering your
 * own facts under a headline" — so the row list needs its own.
 *
 * `Facts` over-claims, in a package whose voice is built on not over-claiming:
 * the app supplies whatever it supplies, and calling it a fact dresses an
 * app's `is_bot` guess as truth. `Fields` was the name until 2026-09-14 and
 * lost it to a collision that matters where this package is consumed — in
 * Filament a `Field` is an editable form input, and the read-only counterpart
 * is an `Entry`, which a feed cannot borrow either because Atom already spends
 * `entry` on a feed item. `KeyValue` is what Filament calls the read-only
 * shape itself, minus the suffix.
 *
 * ## What this never learns
 *
 * What a key MEANS. The app decides that `is_bot` reads as "Looked automated",
 * because the app is the only thing that knows it. This class owns the body
 * type and nothing else.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the body at read time, never write it back.
 */
class KeyValue implements FeedBody
{
    use Conditionable;
    use HasPayload;

    /**
     * Each row as given; a row's own `placeholder` only when it said one, so the
     * body's default applies to the rest when the body is used.
     *
     * @var list<array{key: string, value: string|int|float|bool|null, verbatim: bool, placeholder?: string|null}>
     */
    private array $rows = [];

    private ?string $title = null;

    private ?string $defaultPlaceholder = null;

    final protected function __construct() {}

    /**
     * Start the rows. Every argument is optional and has a method of the same name.
     *
     * @param  array<array-key, mixed>  $items  a `key => value` map, or a list of
     *                                          explicit `['key' => …, 'value' => …]` pairs
     * @param  string|null  $title  a line above the pairs, when the headline does not already say it
     * @param  string|null  $defaultPlaceholder  the sentence an absent value gets, if any — see {@see defaultPlaceholder()}
     */
    public static function make(array $items = [], ?string $title = null, ?string $defaultPlaceholder = null): static
    {
        return (new static)->items($items)->title($title)->defaultPlaceholder($defaultPlaceholder);
    }

    /**
     * Add rows. A map MERGES, as `View::with()` does: a key already here takes
     * the later value and keeps its place. A key with a value sets that one row.
     * A list of explicit `['key' => …, 'value' => …]` pairs APPENDS, which is
     * how a key repeats.
     *
     *     ->items(['Address' => KeyValue::verbatim($ip), 'Seat' => $seat])
     *     ->items('Looked automated', $fetch->is_bot)
     *
     * @param  array<array-key, mixed>|string  $key
     */
    public function items(array|string $key, mixed $value = null): static
    {
        foreach (is_string($key) ? [$key => $value] : $key as $name => $row) {
            // Two shapes, because both are natural to write: a map, and a list
            // of explicit pairs for an app that needs to repeat a key or keep an
            // explicit order it built elsewhere.
            $isRow = is_array($row) && (array_key_exists('value', $row) || array_key_exists('key', $row));

            /** @var array<string, mixed> $spec */
            $spec = $isRow ? $row : ['key' => $name, 'value' => $row];

            $label = $spec['key'] ?? $name;

            $normalized = [
                'key' => is_scalar($label) ? (string) $label : '',
                'value' => self::scalar($spec['value'] ?? null),
                // Addresses, user agents, ids: the values a reader compares
                // character by character rather than reads. A renderer gives
                // these one line and an ellipsis for that reason.
                'verbatim' => (bool) ($spec['verbatim'] ?? false),
            ];

            if (array_key_exists('placeholder', $spec)) {
                $normalized['placeholder'] = is_string($spec['placeholder']) ? $spec['placeholder'] : null;
            }

            $existing = $isRow && is_int($name) ? false : array_search($normalized['key'], array_column($this->rows, 'key'), true);

            if ($existing === false) {
                $this->rows[] = $normalized;
            } else {
                $this->rows[$existing] = $normalized;
            }
        }

        return $this;
    }

    /** A line above the pairs, when the headline does not already say it. */
    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * The sentence every absent value gets, unless its row says its own.
     *
     * AN ABSENT VALUE IS SILENT BY DEFAULT, and per row because one
     * payload can hold both kinds of absence: a field that is
     * genuinely unknown, and one whose emptiness is itself the
     * answer.
     *
     * The first draft printed "Not known" for every null and the
     * first consumer was right to refuse it — an unauthenticated
     * fetch has no session, which is also exactly what a private
     * window looks like, so a fixed placeholder turns silence into
     * a confident claim about every row that had nothing to say.
     * "Not known" and "could not be told either way" are different
     * sentences and only the domain knows which one it has.
     */
    public function defaultPlaceholder(?string $defaultPlaceholder): static
    {
        $this->defaultPlaceholder = $defaultPlaceholder;

        return $this;
    }

    /**
     * Mark a value as reproduced exactly: compared rather than read.
     *
     * An id, an address, a user agent — a string somebody checks against
     * another string. NAMED FOR THE FACT AND NOT THE TYPEFACE. It said `mono`
     * until 2026-09-14, which put a font in the payload and let an app reach
     * past the renderer to specify appearance; fixed width is a renderer's
     * reasonable conclusion from "reproduced exactly", not an instruction
     * core has any business giving.
     *
     * @return array{value: string|int|float|bool|null, verbatim: true}
     */
    public static function verbatim(mixed $value): array
    {
        return ['value' => self::scalar($value), 'verbatim' => true];
    }

    /**
     * Give one absence its own word, where the emptiness is the answer.
     *
     * `placeholder()` rather than `defaultPlaceholder()`, which sets the body's default
     * word for every row.
     *
     * Beside {@see verbatim()} so a literal map never has to drop into the
     * payload's own shape to say one thing about one pair.
     *
     * @return array{value: string|int|float|bool|null, placeholder: string}
     */
    public static function placeholder(mixed $value, string $word): array
    {
        return ['value' => self::scalar($value), 'placeholder' => $word];
    }

    /**
     * `Storyfeed/Body/KeyValue` — the VOCABULARY'S name, not a package's.
     *
     * A body outlives whichever library defined it ({@see FeedBody}), so the
     * name must not contain the library: this body type has already moved
     * packages once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix
     * would have moved with it. The name is a pure lookup key — no reflection,
     * no autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/KeyValue';
    }

    public static function version(): int
    {
        return 2;
    }

    public static function upgrade(array $payload, int $from): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $title = $payload['title'] ?? null;
        $default = array_key_exists('defaultPlaceholder', $payload)
            ? $payload['defaultPlaceholder']
            : ($from < 2 ? ($payload['missing'] ?? null) : null);
        $default = is_string($default) ? $default : null;

        return [
            'title' => is_string($title) ? $title : null,
            'defaultPlaceholder' => $default,
            'items' => array_values(array_map(function (array $row) use ($from, $default): array {
                $placeholder = array_key_exists('placeholder', $row)
                    ? $row['placeholder']
                    : ($from < 2 && array_key_exists('missing', $row) ? $row['missing'] : $default);

                unset($row['missing']);

                return [...$row, 'placeholder' => is_string($placeholder) ? $placeholder : null];
            }, array_filter($items, is_array(...)))),
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, title: string|null, defaultPlaceholder: string|null, items: array<int, array{key: string, value: string|int|float|bool|null, verbatim: bool, placeholder: string|null}>}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::bodyType(),
            self::VERSION => self::version(),
            'title' => $this->title,
            'defaultPlaceholder' => $this->defaultPlaceholder,
            'items' => array_map(
                fn (array $row): array => [...$row, 'placeholder' => array_key_exists('placeholder', $row) ? $row['placeholder'] : $this->defaultPlaceholder],
                $this->rows,
            ),
        ];
    }

    /**
     * Values are STORED, so they must survive a JSON round trip.
     *
     * An `Htmlable` is accepted at the door and flattened to its string here
     * rather than kept: it would serialize to `{}` in a JSON column and come
     * back as nothing at all, which is the kind of loss that shows up months
     * later in rows nobody can regenerate. An app that wants markup in a value
     * is describing a different body type — `Prose`, or a link on the entity.
     */
    private static function scalar(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof Htmlable => $value->toHtml(),
            $value instanceof Stringable, is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => null,
        };
    }
}
