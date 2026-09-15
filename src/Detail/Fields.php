<?php

namespace Storyfeed\Detail;

use Illuminate\Contracts\Support\Htmlable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedDetail;
use Stringable;

/**
 * Labelled rows under a headline — the commonest form, and the one two
 * consumers hand-wrote independently before it existed.
 *
 *     ->data(Fields::make([
 *         'Address' => Fields::verbatim($fetch->ip),
 *         'Where the address resolved' => $fetch->geo?->describe(),
 *         'Looked automated' => $fetch->is_bot,
 *     ]))
 *
 * ## Why `Fields` and not `Details`
 *
 * A member cannot share a name with its own category. `detail` is the category
 * — it is already the name of the seam in the Filament adapter's
 * `detail_view` config, in `.sf-detail`, and in its README's "Rendering your
 * own facts under a headline" — so the row list needs its own.
 *
 * `Facts` was the first name and it over-claims, in a package whose voice is
 * built on not over-claiming: the app supplies whatever it supplies, and
 * calling it a fact dresses an app's `is_bot` guess as truth.
 *
 * ## What this never learns
 *
 * What a key MEANS. The app decides that `is_bot` reads as "Looked automated",
 * because the app is the only thing that knows it. This class owns the form and
 * nothing else.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the detail at read time, never write it back.
 */
class Fields implements FeedDetail
{
    use HasPayload;

    /**
     * @param  array<int, array{label: string, value: string|int|float|bool|null, verbatim: bool, missing: string|null}>  $rows
     */
    final protected function __construct(
        private readonly array $rows,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rows  a `label => value` map, or a list
     *                                         of explicit `['label' => …, 'value' => …]` rows
     * @param  string|null  $missing  the sentence an absent value gets, if any — see below
     */
    public static function make(array $rows, ?string $missing = null): static
    {
        $normalized = [];

        foreach ($rows as $key => $row) {
            // Two shapes, because both are natural to write: a map, and a list
            // of rows for an app that needs to repeat a label or keep an
            // explicit order it built elsewhere.
            $isRow = is_array($row) && (array_key_exists('value', $row) || array_key_exists('label', $row));

            /** @var array<string, mixed> $spec */
            $spec = $isRow ? $row : ['label' => $key, 'value' => $row];

            $label = $spec['label'] ?? $key;

            $normalized[] = [
                'label' => is_scalar($label) ? (string) $label : '',
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

        return new static($normalized);
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
     * `Storyfeed/Detail/Fields` — the VOCABULARY'S name, not a package's.
     *
     * A detail outlives whichever library defined it ({@see FeedDetail}), so the
     * name must not contain the library: this form has already moved packages
     * once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix would have
     * moved with it. The name is a pure lookup key — no reflection, no
     * autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function name(): string
    {
        return 'Storyfeed/Detail/Fields';
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
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        return ['rows' => array_values(array_filter($rows, is_array(...)))];
    }

    /**
     * @return array{'$detail': string, '$v': int, rows: array<int, array{label: string, value: string|int|float|bool|null, verbatim: bool, missing: string|null}>}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'rows' => $this->rows,
        ];
    }

    /**
     * Values are STORED, so they must survive a JSON round trip.
     *
     * An `Htmlable` is accepted at the door and flattened to its string here
     * rather than kept: it would serialize to `{}` in a JSON column and come
     * back as nothing at all, which is the kind of loss that shows up months
     * later in rows nobody can regenerate. An app that wants markup in a value
     * is describing a different form — `Markdown`, or a link on the entity.
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
