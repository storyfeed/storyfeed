<?php

namespace Storyfeed\Body;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Stringable;

/**
 * Authored Markdown source, never compiled HTML. Rendering belongs to the reader.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the detail at read time.
 */
class Markdown implements FeedBody
{
    use HasPayload;

    final protected function __construct(
        private readonly string $content,
    ) {}

    public static function make(mixed $content): static
    {
        return new static(match (true) {
            is_string($content) => $content,
            is_scalar($content) => (string) $content,
            $content instanceof Stringable, is_object($content) && method_exists($content, '__toString') => (string) $content,
            default => '',
        });
    }

    /**
     * `Storyfeed/Body/Markdown` — the VOCABULARY'S name, not a package's.
     *
     * A detail outlives whichever library defined it ({@see FeedBody}), so the
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
        return 'Storyfeed/Body/Markdown';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        return [
            'content' => is_string($payload['content'] ?? null) ? $payload['content'] : '',
            // Carried explicitly rather than assumed, so the day a sibling
            // encoding exists the stored rows already say which one they are —
            // and so an AS2 serializer has the value it needs without inferring
            // it from the detail's name.
            'mediaType' => 'text/markdown',
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, content: string, mediaType: string}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'content' => $this->content,
            'mediaType' => 'text/markdown',
        ];
    }
}
