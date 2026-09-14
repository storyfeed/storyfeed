<?php

namespace Storyfeed\Detail;

use Illuminate\Contracts\Support\Htmlable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedDetail;
use Stringable;

/**
 * A fragment of text, and where it came from.
 *
 *     FeedEntity::make($clause->reference, data: Excerpt::make($clause->text))
 *
 * The form a pilot's feed was missing. Its headline read
 *
 *     Jasper took "The fee for each subsequent term is agreed at re…" off Harbor Retainer
 *
 * — a quotation truncated into a sentence, which is how a headline stops being
 * a sentence. The quote belongs under it, where it can be as long as it is.
 *
 * ## Why `Excerpt` and not `Blockquote`
 *
 * {@see FeedDetail}: a detail names a FORM, not a component. `<blockquote>`
 * is how this is drawn in Blade today and a Vue or React renderer may not use
 * it — naming the class after the markup would hand every later renderer a
 * decision made for one of them.
 *
 * Attributed speech ("Jasper said …") and a document extract ("… off the
 * retainer") are the same form; they differ only in what the attribution points
 * at, which is a field.
 *
 * A CONVERSATION is core's `FeedThread` (node-level `thread`, painted by the
 * renderer's presenter), not this: the tell is a reply count. This form stays
 * the generic one-passage one and is unchanged by it.
 *
 * ## `truncated` exists because the NAME over-claims
 *
 * "Excerpt" asserts the text is partial, and a comment rendered in full is not.
 * The flag is how a caller says otherwise, and it is the honest fix for a name
 * that was chosen for readability over precision — the same trade `Facts` lost
 * and this one wins, narrowly.
 *
 * ## Not `Change`
 *
 * A passage that was one thing and is now another is two passages and a pair,
 * which is {@see Change}. This form carries ONE passage and says where it came
 * from; it never says what it used to be.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the detail at read time, never write it back.
 */
class Excerpt implements FeedDetail
{
    use HasPayload;

    final protected function __construct(
        private readonly string $text,
        private readonly ?string $from,
        private readonly bool $truncated,
    ) {}

    /**
     * @param  string|null  $from  who or what it came from, when the sentence above does not already say
     * @param  bool  $truncated  whether this is a fragment of something longer
     */
    public static function make(mixed $text, ?string $from = null, bool $truncated = true): static
    {
        return new static(self::text($text), $from, $truncated);
    }

    /**
     * `Storyfeed/Detail/Excerpt` — the VOCABULARY'S name, not a package's.
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
        return 'Storyfeed/Detail/Excerpt';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a payload from a version this class does not know
        // still has to render, because the row is in the database either way.
        return [
            'text' => is_string($payload['text'] ?? null) ? $payload['text'] : '',
            'from' => is_string($payload['from'] ?? null) ? $payload['from'] : null,
            'truncated' => (bool) ($payload['truncated'] ?? false),
        ];
    }

    /**
     * @return array{'$detail': string, '$v': int, text: string, from: string|null, truncated: bool}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'text' => $this->text,
            'from' => $this->from,
            'truncated' => $this->truncated,
        ];
    }

    /**
     * TEXT, and only text.
     *
     * An `Htmlable` is flattened rather than kept — see Fields for why a stored
     * value has to survive a JSON column. It matters more here: this is the one
     * form whose whole content is a string an app quotes from somewhere else,
     * and a quotation rendered as markup is an injection sink pointed at a
     * signed guest link. If the source is authored rich text, that is
     * {@see Markdown}, which says so and is sanitised on the way out.
     */
    private static function text(mixed $text): string
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
