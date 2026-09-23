<?php

namespace Storyfeed\Body;

use Illuminate\Contracts\Support\Htmlable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Stringable;

/**
 * Labelled rows under a headline — the commonest body type, and the one two
 * consumers hand-wrote independently before it existed.
 *
 *     ->data(KeyValue::make([
 *         'Address' => KeyValue::verbatim($fetch->ip),
 *         'Where the address resolved' => $fetch->geo?->describe(),
 *         'Looked automated' => $fetch->is_bot,
 *     ]))
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
    use HasPayload;

    /**
     * @param  array<int, array{key: string, value: string|int|float|bool|null, verbatim: bool, missing: string|null}>  $items
     */
    final protected function __construct(
        private readonly array $items,
        private readonly ?string $title = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items  a `key => value` map, or a list of
     *                                          explicit `['key' => …, 'value' => …]` pairs
     * @param  string|null  $title  a line above the pairs, when the headline does not already say it
     * @param  string|null  $missing  the sentence an absent value gets, if any — see below
     */
    public static function make(array $items, ?string $title = null, ?string $missing = null): static
    {
        $normalized = [];

        foreach ($items as $key => $row) {
            // Two shapes, because both are natural to write: a map, and a list
            // of explicit pairs for an app that needs to repeat a key or keep an
            // explicit order it built elsewhere.
            $isRow = is_array($row) && (array_key_exists('value', $row) || array_key_exists('key', $row));

            /** @var array<string, mixed> $spec */
            $spec = $isRow ? $row : ['key' => $key, 'value' => $row];

            $name = $spec['key'] ?? $key;

            $normalized[] = [
                'key' => is_scalar($name) ? (string) $name : '',
                'value' => self::scalar($spec['value'] ?? null),
                // Addresses, user agents, ids: the values a reader compares
                // character by character rather than reads. A renderer gives
                // these one line and an ellipsis for that reason.
                'verbatim' => (bool) ($spec['verbatim'] ?? false),
                /*
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
                'missing' => array_key_exists('missing', $spec)
                    ? (is_string($spec['missing']) ? $spec['missing'] : null)
                    : $missing,
            ];
        }

        return new static($normalized, $title);
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
     * Beside {@see verbatim()} so a literal map never has to drop into the
     * payload's own shape to say one thing about one pair.
     *
     * @return array{value: string|int|float|bool|null, missing: string}
     */
    public static function missing(mixed $value, string $word): array
    {
        return ['value' => self::scalar($value), 'missing' => $word];
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
    public static function name(): string
    {
        return 'Storyfeed/Body/KeyValue';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Nothing to upgrade at v1 — but the branch exists from the first
        // commit so that the day there IS something, the call site already
        // routes through here rather than needing to be found. Total by
        // contract: an unknown version renders as an empty row list, never as
        // an exception, because the row is in the database either way.
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $title = $payload['title'] ?? null;

        return [
            'title' => is_string($title) ? $title : null,
            'items' => array_values(array_filter($items, is_array(...))),
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, title: string|null, items: array<int, array{key: string, value: string|int|float|bool|null, verbatim: bool, missing: string|null}>}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'title' => $this->title,
            'items' => $this->items,
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
