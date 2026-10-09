<?php

namespace Storyfeed\Body;

use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Storyfeed\Body\Concerns\HasTitle;
use Storyfeed\FeedBody;
use Storyfeed\FeedLink;
use Stringable;

/**
 * Rows and columns, for when `KeyValue`'s label → value is not enough. The
 * arguments copy Artisan's `$this->table($headers, $rows)`:
 *
 *     ->body(Table::make(['Plan', 'Seats', 'Price'], [
 *         ['Starter', 3, '$9'],
 *         ['Team', 25, '$49'],
 *     ])->title('Pricing changes'))
 *
 * …and every argument has a method of the same name, so the order stops
 * mattering when it is built a row at a time:
 *
 *     Table::make()
 *         ->headers(['Item', 'Qty', 'Price'])
 *         ->row(['Starter plan', 1, '$9.00'])
 *         ->row(['Extra seats', 4, '$40.00'])
 *         ->footer(['Total', '', '$49.00']);
 *
 * Stored:
 *
 *     {"$body": "Storyfeed/Body/Table", "$v": 1, "$fallback": "Pricing changes",
 *      "title": "Pricing changes", "headers": ["Plan", "Seats", "Price"],
 *      "rows": [["Starter", 3, "$9"], ["Team", 25, "$49"]]}
 *
 * ## A cell is text, a number, nothing, or a link
 *
 * `string | int | float | null | FeedLink`, the same values an `ItemList`
 * item may be. Text is always plain: a renderer escapes it, never parses it
 * as Markdown or HTML, and keeps its line breaks, because an address or a
 * note spans lines. `null` is an empty cell. Bodies never nest
 * ({@see \Storyfeed\Contracts\FeedBody} rule 4), so a cell cannot hold a
 * `Prose` or any other body.
 *
 * AMOUNTS ARRIVE FORMATTED. The body never computes a total and never formats
 * a number: `'$49.00'` is what the app means, and the footer row says it.
 *
 * ## Footer rows cover receipts and invoices
 *
 * `footer()` appends one row under the others: a subtotal, the tax, the
 * total. That is why there is no separate line-items body.
 *
 * ## A change is a table
 *
 * What changed on a record is a Field | Before | After table of the changed
 * fields only, so there is no separate change body:
 *
 *     Table::make()->headers(['Field', 'Before', 'After'])
 *         ->rows($changes->map(fn ($c) => [$c->label, $c->before, $c->after]));
 *
 * ## No alignment, no styling
 *
 * The payload says what the cells are, never how they look. A renderer draws
 * the table however it chooses.
 */
class Table extends FeedBody
{
    use HasTitle;

    /** @var list<mixed> */
    protected array $headers = [];

    /**
     * Rows as given. Cells are checked and links turned into arrays when the
     * table is USED, so a `FeedLink` configured after it was added still counts.
     *
     * @var list<array<array-key, mixed>>
     */
    protected array $rows = [];

    /** @var list<array<array-key, mixed>> */
    protected array $footer = [];

    /**
     * Start a table. Every argument is optional and has a method of the same name.
     *
     * @param  array<array-key, mixed>  $headers  the header row; empty for none, as in Artisan
     * @param  iterable<array<array-key, mixed>>  $rows  each row a list of cells
     * @param  string|null  $title  a line above the table, when the headline does not already say it
     */
    protected function __construct(array $headers = [], iterable $rows = [], ?string $title = null)
    {
        $this->headers($headers)->rows($rows)->title($title);
    }

    /**
     * The header row, replacing any already set. An empty array means no
     * header row.
     *
     * @param  array<array-key, mixed>  $headers
     */
    public function headers(array $headers): static
    {
        $this->headers = array_values($headers);

        return $this;
    }

    /**
     * The rows, replacing any already added.
     *
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public function rows(iterable $rows): static
    {
        $this->rows = [];

        foreach ($rows as $row) {
            $this->row($row);
        }

        return $this;
    }

    /**
     * Append one row. A loop calls this, and the order is kept.
     *
     * @param  array<array-key, mixed>  $row
     */
    public function row(array $row): static
    {
        $this->rows[] = $row;

        return $this;
    }

    /**
     * Append one footer row: a subtotal, the tax, the total. Same cells as
     * any row; the amounts arrive formatted.
     *
     * @param  array<array-key, mixed>  $row
     */
    public function footer(array $row): static
    {
        $this->footer[] = $row;

        return $this;
    }

    /**
     * `Storyfeed/Body/Table` — the VOCABULARY'S name, not a package's.
     *
     * A body outlives whichever library defined it
     * ({@see \Storyfeed\Contracts\FeedBody}), so the name must not contain the
     * library. Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Table';
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a row from a version this class does not know
        // still has to render. A cell that is not one becomes an empty cell
        // rather than being dropped, which would shift the columns after it.
        $title = $payload['title'] ?? null;
        $headers = array_map(
            fn (mixed $header): string => is_string($header) || is_int($header) || is_float($header) ? (string) $header : '',
            self::list($payload['headers'] ?? null),
        );
        $rows = array_map(self::storedRow(...), array_filter(self::list($payload['rows'] ?? null), is_array(...)));
        $footer = array_map(self::storedRow(...), array_filter(self::list($payload['footer'] ?? null), is_array(...)));
        $width = max([count($headers), ...array_map(count(...), [...$rows, ...$footer])]);

        return [
            'title' => is_string($title) ? $title : null,
            'headers' => $headers,
            'rows' => array_map(fn (array $row): array => array_pad($row, $width, null), array_values($rows)),
            'footer' => array_map(fn (array $row): array => array_pad($row, $width, null), array_values($footer)),
        ];
    }

    protected function body(): array
    {
        $headers = array_map(self::header(...), $this->headers);
        $rows = array_map(fn (array $row): array => $this->cells($row), $this->rows);
        $footer = array_map(fn (array $row): array => $this->cells($row), $this->footer);

        // Ragged rows are padded to the widest row, never rejected. With
        // headers, a row wider than them has a column nobody named.
        if ($headers !== []) {
            foreach (['row' => $rows, 'footer row' => $footer] as $kind => $list) {
                foreach ($list as $index => $row) {
                    if (count($row) > count($headers)) {
                        throw new InvalidArgumentException(sprintf(
                            'Table %s %d has %d cells, but the table has %d headers.',
                            $kind, $index + 1, count($row), count($headers),
                        ));
                    }
                }
            }
        }

        $width = max([count($headers), ...array_map(count(...), [...$rows, ...$footer])]);

        return [
            'title' => $this->title,
            'headers' => $headers,
            'rows' => array_map(fn (array $row): array => array_pad($row, $width, null), $rows),
            'footer' => array_map(fn (array $row): array => array_pad($row, $width, null), $footer),
        ];
    }

    protected static function defaults(): array
    {
        return ['title' => null, 'headers' => [], 'footer' => []];
    }

    /** A titled table falls back to its title, where a renderer cannot draw a table. */
    protected function defaultFallback(): ?string
    {
        return $this->title;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string|int|float|array{label: string, href: string|null}|null>
     */
    private function cells(array $row): array
    {
        return array_map(fn (mixed $cell): string|int|float|array|null => match (true) {
            $cell === null, is_string($cell), is_int($cell), is_float($cell) => $cell,
            $cell instanceof FeedLink => $cell->toPayload(),
            $cell instanceof Htmlable => $cell->toHtml(),
            $cell instanceof Stringable => (string) $cell,
            default => throw new InvalidArgumentException(sprintf(
                'A Table cell is a string, int, float, null or FeedLink; %s given. Format the value before adding it.',
                get_debug_type($cell),
            )),
        }, array_values($row));
    }

    private static function header(mixed $header): string
    {
        return match (true) {
            is_string($header) => $header,
            $header === null => '',
            is_int($header), is_float($header), $header instanceof Stringable => (string) $header,
            default => throw new InvalidArgumentException(sprintf(
                'A Table header is a string; %s given.',
                get_debug_type($header),
            )),
        };
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string|int|float|array{label: string, href: string|null}|null>
     */
    private static function storedRow(array $row): array
    {
        return array_map(
            fn (mixed $cell): string|int|float|array|null => match (true) {
                $cell === null, is_string($cell), is_int($cell), is_float($cell) => $cell,
                default => FeedLink::from($cell)?->toPayload(),
            },
            array_values($row),
        );
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
