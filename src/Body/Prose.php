<?php

namespace Storyfeed\Body;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Stringable;

/**
 * Authored text, carried as SOURCE, with the encoding that says how to read it.
 *
 *     Prose::make($order->summary())                  // printed as written
 *     Prose::markdown($note->body)                    // parsed by the renderer
 *     Prose::verbatim($deploy->output)                // reproduced exactly
 *     Prose::code($migration->source, 'text/x-php')   // reproduced exactly, and it knows what it is
 *
 * ## Why one form and not four
 *
 * This was `Markdown` until 2026-09-14, and `Markdown` hardcoded
 * `mediaType: 'text/markdown'` — a constant restating the class name, which
 * is the tell that the name was one value of its own field. The encoding was
 * always the data; the class was named after the most common one.
 *
 * A SEPARATE `Snippet` FORM WAS REJECTED, and degradation is why. An
 * unrecognised FORM draws nothing, so a renderer that had never heard of
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
    use HasPayload;

    final protected function __construct(
        private readonly string $content,
        private readonly string $mediaType,
        private readonly bool $verbatim,
        private readonly ?string $title,
    ) {}

    /** Plain text, printed as written. */
    public static function make(mixed $content, ?string $title = null): static
    {
        return new static(self::text($content), 'text/plain', false, $title);
    }

    /** Markdown source, for a renderer to parse. */
    public static function markdown(mixed $content, ?string $title = null): static
    {
        return new static(self::text($content), 'text/markdown', false, $title);
    }

    /** An HTML fragment. Named rather than defaulted: the renderer must sanitize it. */
    public static function html(mixed $content, ?string $title = null): static
    {
        return new static(self::text($content), 'text/html', false, $title);
    }

    /** Reproduced exactly: nothing parsed, the whitespace kept. */
    public static function verbatim(mixed $content, string $mediaType = 'text/plain', ?string $title = null): static
    {
        return new static(self::text($content), $mediaType, true, $title);
    }

    /** Verbatim, and it knows the language — `text/x-php`, `application/json`. */
    public static function code(mixed $content, string $mediaType, ?string $title = null): static
    {
        return new static(self::text($content), $mediaType, true, $title);
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
     * A form outlives whichever library defined it ({@see FeedBody}), so the
     * name must not contain the library. It is a pure lookup key — no
     * reflection, no autoloading — so it need not resolve to anything.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function name(): string
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
            // A row written before this form carried an encoding is markdown,
            // because that is what the form it replaced could only ever hold.
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
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'content' => $this->content,
            'mediaType' => $this->mediaType,
            'verbatim' => $this->verbatim,
            'title' => $this->title,
        ];
    }
}
