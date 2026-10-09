<?php

namespace Storyfeed\Body;

use Storyfeed\Body\Concerns\HasContent;
use Storyfeed\Body\Concerns\HasTitle;
use Storyfeed\FeedBody;

/**
 * Authored text, carried as SOURCE, with the encoding that says how to read it.
 *
 *     Prose::make($booking->summary())                // printed as written
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
class Prose extends FeedBody
{
    use HasContent;
    use HasTitle;

    protected string $mediaType = 'text/plain';

    protected bool $verbatim = false;

    /**
     * Plain text, printed as written. Both arguments are optional and have a
     * method of the same name; `content` must be set before the body is used.
     */
    protected function __construct(mixed $content = null, ?string $title = null)
    {
        $this->content($content)->title($title);
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

    /** The encoding: `text/plain`, `text/markdown`, `text/html`, or a language. */
    public function mediaType(string $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    protected static function build(mixed $content, string $mediaType, bool $verbatim, ?string $title): static
    {
        $prose = static::make($content, $title)->mediaType($mediaType);
        $prose->verbatim = $verbatim;

        return $prose;
    }

    /**
     * `Storyfeed/Body/Prose` — the VOCABULARY'S name, not a package's.
     *
     * A body type outlives whichever library defined it ({@see \Storyfeed\Contracts\FeedBody}), so
     * the name must not contain the library. It is a pure lookup key — no
     * reflection, no autoloading — so it need not resolve to anything.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Prose';
    }

    /** 2 since 2026-10-09: `mediaType`, `verbatim` and `title` are written only when they differ from their defaults. */
    public static function version(): int
    {
        return 2;
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
            // A v1 row without an encoding was written before this body type
            // carried one, so it is markdown: that is all the one it replaced
            // could ever hold. From v2 an absent encoding is the default.
            'mediaType' => is_string($mediaType) && $mediaType !== '' ? $mediaType : ($from >= 2 ? 'text/plain' : 'text/markdown'),
            'verbatim' => (bool) ($payload['verbatim'] ?? false),
            'title' => is_string($title) ? $title : null,
        ];
    }

    protected function body(): array
    {
        return [
            'content' => $this->required($this->content, 'content'),
            'mediaType' => $this->mediaType,
            'verbatim' => $this->verbatim,
            'title' => $this->title,
        ];
    }

    protected static function defaults(): array
    {
        return ['mediaType' => 'text/plain', 'verbatim' => false, 'title' => null];
    }
}
