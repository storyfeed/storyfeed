<?php

namespace Storyfeed\Body;

use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Stringable;

/**
 * Authored text, carried as SOURCE, with the encoding that says how to read it.
 *
 *     Prose::make($order->summary())                  // printed as written
 *     Prose::markdown($note->body)                    // parsed by the renderer
 *     Prose::verbatim($deploy->output)                // reproduced exactly
 *     Prose::code($migration->source, 'text/x-php')   // reproduced exactly, and it knows what it is
 *
 * ## Why one body type and not four
 *
 * This was `Markdown` until 2026-09-14, and `Markdown` hardcoded
 * `mediaType: 'text/markdown'` — a constant restating the class name, which
 * is the tell that the name was one value of its own field. The encoding was
 * always the data; the class was named after the most common one.
 *
 * A SEPARATE `Snippet` BODY TYPE WAS REJECTED, and degradation is why. An
 * unrecognised BODY TYPE draws nothing, so a renderer that had never heard of
 * `Snippet` would lose the text entirely. An unrecognised MEDIA TYPE loses
 * nothing: the characters are still shown, just without colour. One of those
 * failure modes is a blank space and the other is a plainer block.
 *
 * ## `verbatim` is the fact; monospace is a renderer's conclusion
 *
 * Reproduced exactly means nothing is parsed and the whitespace somebody typed
 * survives. It does NOT mean a typeface: a verbatim quotation from a letter
 * reads fine in a serif face, and only aligned output needs fixed width. Core
 * states the fact and the renderer draws what follows from it, which is the
 * same line {@see KeyValue::verbatim()} holds for a value compared rather than
 * read.
 *
 * ## A renderer parses only what it positively recognises
 *
 * Markdown and HTML are parsed; everything else is shown as characters. That
 * rule runs in the safe direction — a media type a renderer half-knows is an
 * injection surface, and the honest default, show what was written, is also
 * the harmless one. `html()` exists so that reaching for the encoding a
 * renderer must sanitize is a decision somebody typed, never one they got by
 * leaving an argument out.
 */
class Prose implements FeedBody
{
    use Conditionable;
    use HasPayload;

    private ?string $content = null;

    private string $mediaType = 'text/plain';

    private bool $verbatim = false;

    private ?string $title = null;

    final protected function __construct() {}

    /**
     * Plain text, printed as written. Both arguments are optional and have a
     * method of the same name; `content` must be set before the body is used.
     */
    public static function make(mixed $content = null, ?string $title = null): static
    {
        return static::build($content, 'text/plain', false, $title);
    }

    /** Markdown source, for a renderer to parse. */
    public static function markdown(mixed $content = null, ?string $title = null): static
    {
        return static::build($content, 'text/markdown', false, $title);
    }

    /** An HTML fragment. Named rather than defaulted: the renderer must sanitize it. */
    public static function html(mixed $content = null, ?string $title = null): static
    {
        return static::build($content, 'text/html', false, $title);
    }

    /** Reproduced exactly: nothing parsed, the whitespace kept. */
    public static function verbatim(mixed $content = null, string $mediaType = 'text/plain', ?string $title = null): static
    {
        return static::build($content, $mediaType, true, $title);
    }

    /** Verbatim, and it knows the language — `text/x-php`, `application/json`. */
    public static function code(mixed $content, string $mediaType, ?string $title = null): static
    {
        return static::build($content, $mediaType, true, $title);
    }

    /** The text itself. */
    public function content(mixed $content): static
    {
        $this->content = self::text($content);

        return $this;
    }

    /** A line above the text, when the headline does not already say it. */
    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** The encoding: `text/plain`, `text/markdown`, `text/html`, or a language. */
    public function mediaType(string $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    protected static function build(mixed $content, string $mediaType, bool $verbatim, ?string $title): static
    {
        $prose = (new static)->mediaType($mediaType)->title($title);
        $prose->verbatim = $verbatim;

        return $content === null ? $prose : $prose->content($content);
    }

    private static function text(mixed $content): string
    {
        return match (true) {
            is_string($content) => $content,
            is_scalar($content) => (string) $content,
            $content instanceof Stringable, is_object($content) && method_exists($content, '__toString') => (string) $content,
            default => '',
        };
    }

    /**
     * `Storyfeed/Body/Prose` — the VOCABULARY'S name, not a package's.
     *
     * A body type outlives whichever library defined it ({@see FeedBody}), so
     * the name must not contain the library. It is a pure lookup key — no
     * reflection, no autoloading — so it need not resolve to anything.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Prose';
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
        $mediaType = $payload['mediaType'] ?? null;
        $title = $payload['title'] ?? null;

        return [
            'content' => is_string($payload['content'] ?? null) ? $payload['content'] : '',
            // A row written before this body type carried an encoding is
            // markdown: that is all the one it replaced could ever hold.
            'mediaType' => is_string($mediaType) && $mediaType !== '' ? $mediaType : 'text/markdown',
            'verbatim' => (bool) ($payload['verbatim'] ?? false),
            'title' => is_string($title) ? $title : null,
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, content: string, mediaType: string, verbatim: bool, title: string|null}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::bodyType(),
            self::VERSION => self::version(),
            'content' => $this->content ?? throw IncompleteFeedValue::missing(static::class, 'content'),
            'mediaType' => $this->mediaType,
            'verbatim' => $this->verbatim,
            'title' => $this->title,
        ];
    }
}
