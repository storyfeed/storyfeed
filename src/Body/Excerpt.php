<?php

namespace Storyfeed\Body;

use Illuminate\Contracts\Support\Htmlable;
use Storyfeed\FeedBody;
use Stringable;

/**
 * A fragment of text, and where it came from.
 *
 *     FeedEntity::make()->label($clause->reference)->body(Excerpt::make()->text($clause->text))
 *
 * The body type a pilot's feed was missing. Its headline read
 *
 *     Jasper took "The fee for each subsequent term is agreed at re…" off Harbor Retainer
 *
 * — a quotation truncated into a sentence, which is how a headline stops being
 * a sentence. The quote belongs under it, where it can be as long as it is.
 *
 * ## Why `Excerpt` and not `Blockquote`
 *
 * {@see \Storyfeed\Contracts\FeedBody}: a body type names what the data IS, not a component.
 * `<blockquote>` is how this is drawn in Blade today and a Vue or React
 * renderer may not use it — naming the class after the markup would hand every
 * later renderer a decision made for one of them.
 *
 * Attributed speech ("Jasper said …") and a document extract ("… off the
 * retainer") are the same body type; they differ only in what the attribution
 * points at, which is a field.
 *
 * ## `truncated` exists because the NAME over-claims
 *
 * "Excerpt" asserts the text is partial, and a comment rendered in full is not.
 * The flag is how a caller says otherwise, and it is the honest fix for a name
 * that was chosen for readability over precision — the same trade `Facts` lost
 * and this one wins, narrowly.
 *
 * ## Not a before and after
 *
 * A passage that was one thing and is now another is two values, which belong
 * in the activity's `data` as from and to, with a headline that says so. This
 * body type carries ONE passage and says where it
 * came from; it never says what it used to be.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the body at read time, never write it back.
 */
class Excerpt extends FeedBody
{
    protected ?string $text = null;

    protected ?string $from = null;

    protected bool $truncated = true;

    /**
     * Start an excerpt. Every argument is optional and has a method of the
     * same name; `text` must be set before the excerpt is used.
     *
     * @param  mixed  $text  the passage; markup is flattened to text
     * @param  string|null  $from  who or what it came from, when the sentence above does not already say
     * @param  bool  $truncated  whether this is a fragment of something longer
     */
    protected function __construct(mixed $text = null, ?string $from = null, bool $truncated = true)
    {
        $this->from($from)->truncated($truncated);

        if ($text !== null) {
            $this->text($text);
        }
    }

    /**
     * The passage. Markup is flattened to text: see {@see flatten()}.
     */
    public function text(mixed $text): static
    {
        $this->text = self::flatten($text);

        return $this;
    }

    /** Who or what it came from, when the sentence above does not already say. */
    public function from(?string $from): static
    {
        $this->from = $from;

        return $this;
    }

    /** Whether this is a fragment of something longer. True unless told otherwise. */
    public function truncated(bool $truncated = true): static
    {
        $this->truncated = $truncated;

        return $this;
    }

    /**
     * `Storyfeed/Body/Excerpt` — the VOCABULARY'S name, not a package's.
     *
     * A body outlives whichever library defined it ({@see \Storyfeed\Contracts\FeedBody}), so the
     * name must not contain the library: this body type has already moved
     * packages once, and a `storyfeed-ui/` or any other package's prefix
     * would have moved with it. The name is a pure lookup key — no reflection,
     * no autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Excerpt';
    }

    /** 2 since 2026-10-09: `from` and `truncated` are written only when they differ from their defaults. */
    public static function version(): int
    {
        return 2;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a payload from a version this class does not know
        // still has to render, because the row is in the database either way.
        // A v1 row always wrote `truncated`, so one without it was written by
        // hand and reads as whole; from v2 an absent flag is the default.
        return [
            'text' => is_string($payload['text'] ?? null) ? $payload['text'] : '',
            'from' => is_string($payload['from'] ?? null) ? $payload['from'] : null,
            'truncated' => (bool) ($payload['truncated'] ?? $from >= 2),
        ];
    }

    protected function body(): array
    {
        return [
            'text' => $this->required($this->text, 'text'),
            'from' => $this->from,
            'truncated' => $this->truncated,
        ];
    }

    protected static function defaults(): array
    {
        return ['from' => null, 'truncated' => true];
    }

    /**
     * TEXT, and only text.
     *
     * An `Htmlable` is flattened rather than kept — see KeyValue for why a
     * stored value has to survive a JSON column. It matters more here: this is
     * the one body type whose whole content is a string an app quotes from
     * somewhere else, and a quotation rendered as markup is an injection sink
     * pointed at a signed guest link. If the source is authored rich text, that
     * is {@see Prose}, which says so and is sanitised on the way out.
     */
    private static function flatten(mixed $text): string
    {
        return match (true) {
            is_string($text) => $text,
            $text instanceof Htmlable => strip_tags($text->toHtml()),
            is_scalar($text) => (string) $text,
            $text instanceof Stringable, is_object($text) && method_exists($text, '__toString') => (string) $text,
            default => '',
        };
    }
}
