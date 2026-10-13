<?php

namespace Storyfeed\Body;

use Illuminate\Contracts\Support\Htmlable;
use Storyfeed\Body\Concerns\HasTitle;
use Storyfeed\FeedBody;
use Stringable;

/**
 * Source text, and the language it is in when that is known.
 *
 *     body: Code::make($migration->source)->language('php')->title('2026_10_01_add_shipped_at')
 *     body: Code::make($deploy->diff)->language('diff')
 *
 * A block of code the row is about — a migration, a diff, a query — drawn by
 * the official kits as a plain `<pre><code>` inside `.prose`. Syntax
 * highlighting is a renderer's choice, not the payload's: a kit that
 * highlights maps the language, and one that doesn't shows the same
 * characters.
 *
 * ## `language` is a name, not an encoding
 *
 * {@see Prose} says how writing is read, and a media type (`text/x-php`) is
 * an encoding's statement. This says what the text is, with the word a
 * highlighter takes — `php`, `diff`, `tsx`, the class a `<code>` element
 * carries. Null, the default, means nobody named the language, and the block
 * still draws, bare, losing nothing.
 *
 * ## TEXT, and only text
 *
 * The source is stored as its string, flattened as an {@see Excerpt}'s
 * passage is: code is drawn as the characters somebody wrote, so markup in
 * it is stripped on the way in, never kept as an instruction. A renderer
 * escapes the rest.
 *
 * ## The fallback line is the title, else the first line of the source
 *
 * Where a renderer cannot draw this body's type, the title names the code;
 * without one, the first line does — the `-` or `+` a diff opens with, or a
 * function's signature.
 */
class Code extends FeedBody
{
    use HasTitle;

    protected ?string $source = null;

    protected ?string $language = null;

    /**
     * Start a block of code. Every argument is optional and has a method of
     * the same name; `source` must be set before the body is used.
     *
     * @param  mixed  $source  the code; markup is flattened to text
     * @param  string|null  $language  what the code is, as a highlighter names it
     * @param  string|null  $title  a line above the code, when the headline does not already say it
     */
    protected function __construct(mixed $source = null, ?string $language = null, ?string $title = null)
    {
        $this->language($language)->title($title);

        if ($source !== null) {
            $this->source($source);
        }
    }

    /**
     * The code. Markup is flattened to text: see the class notes.
     */
    public function source(mixed $source): static
    {
        $this->source = self::flatten($source);

        return $this;
    }

    /**
     * What the code is, as a highlighter names it: `php`, `diff`, `tsx`.
     * Null means the language was not known.
     */
    public function language(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    /** `Storyfeed/Body/Code` — the vocabulary's name, as every core body's is. */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Code';
    }

    /**
     * Total by contract: a row from a version this class does not know still
     * has to render. A language that is not a word is none, and the code
     * still shows, bare.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        $language = $payload['language'] ?? null;

        return [
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : null,
            'language' => is_string($language) && $language !== '' ? $language : null,
            'source' => is_string($payload['source'] ?? null) ? $payload['source'] : '',
        ];
    }

    protected function body(): array
    {
        return [
            'title' => $this->title,
            'language' => $this->language,
            'source' => $this->required($this->source, 'source'),
        ];
    }

    protected static function defaults(): array
    {
        return ['title' => null, 'language' => null];
    }

    /** The title, else the first line of the source: one line that still says what the code is. */
    protected function defaultFallback(): ?string
    {
        return $this->title ?? ($this->source === null ? null : rtrim(explode("\n", $this->source, 2)[0], "\r"));
    }

    /**
     * TEXT, and only text. An `Htmlable` is flattened rather than kept — see
     * the class notes — a scalar or a `Stringable` becomes its string, and
     * anything else is no code at all.
     */
    private static function flatten(mixed $source): string
    {
        return match (true) {
            is_string($source) => $source,
            $source instanceof Htmlable => strip_tags($source->toHtml()),
            is_scalar($source) => (string) $source,
            $source instanceof Stringable, is_object($source) && method_exists($source, '__toString') => (string) $source,
            default => '',
        };
    }
}
