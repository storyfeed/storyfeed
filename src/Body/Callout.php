<?php

namespace Storyfeed\Body;

use Storyfeed\Body\Concerns\HasContent;
use Storyfeed\Body\Concerns\HasTitle;
use Storyfeed\FeedBody;

/**
 * A short note with an intent, after Filament's callout: a sentence the row
 * wants beside it, and how it reads.
 *
 *     body: Callout::make('This order ships from the backup warehouse.')->intent('warning')
 *     body: Callout::make('Storyfeed is pre-1.0 until 1.0 ships.')->title('Pin a version')->info()
 *
 * The shape of an aside a row carries because the headline should not say
 * it: a caveat, a caution, a state the app knows and the reader should. A
 * fixed, app-written sentence — the provider's raw error stays out of the
 * row on purpose.
 *
 * ## The intent is an open, app-owned word
 *
 * The same rule a glyph's intent follows: core ships no vocabulary and
 * validates none, and a renderer maps the word to its palette. `note`, the
 * default, `info`, `success`, `warning` and `danger` are the common ones the
 * kits map, and anything else reads as a note. The shortcuts — `info()`,
 * `success()`, `warning()`, `danger()` — spell those four as Filament's own
 * callout does, with the house words: `title` and `content` where Filament
 * says heading and description, and `intent` where Filament says status,
 * because Storyfeed already uses intent for glyphs.
 *
 * ## Named `Callout`, not `Alert` or `Note`
 *
 * An alert reads as a dismissible system notice and is easily confused with
 * notifications; a note is an Activity Streams object type. A callout is
 * prose the row wants beside it, which is what this is.
 *
 * ## The fallback line is the title, else the content
 *
 * Where a renderer cannot draw this body's type, the note itself is the
 * line, which is the whole of it at its smallest.
 */
class Callout extends FeedBody
{
    use HasContent;
    use HasTitle;

    protected string $intent = 'note';

    /**
     * Start a callout. Every argument is optional and has a method of the
     * same name; `content` must be set before the body is used.
     *
     * @param  mixed  $content  the note, a sentence or two, as plain text
     * @param  string|null  $title  a line above the note, when the headline does not already say it
     * @param  string  $intent  how the note reads; an app-owned word
     */
    protected function __construct(mixed $content = null, ?string $title = null, string $intent = 'note')
    {
        $this->content($content)->title($title)->intent($intent);
    }

    /**
     * How the note reads: an open, app-owned word, as a glyph's intent is.
     * Core validates none; a renderer maps the common ones and draws
     * anything else as a note.
     */
    public function intent(string $intent): static
    {
        $this->intent = $intent;

        return $this;
    }

    /** An informational note: `intent('info')`. */
    public function info(): static
    {
        return $this->intent('info');
    }

    /** A positive note: `intent('success')`. */
    public function success(): static
    {
        return $this->intent('success');
    }

    /** A caution: `intent('warning')`. */
    public function warning(): static
    {
        return $this->intent('warning');
    }

    /** Something went wrong: `intent('danger')`. */
    public function danger(): static
    {
        return $this->intent('danger');
    }

    /** `Storyfeed/Body/Callout` — the vocabulary's name, as every core body's is. */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Callout';
    }

    /**
     * Total by contract: a row from a version this class does not know still
     * has to render. An intent that is not a word reads as a note, which is
     * the default anyway.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        $intent = $payload['intent'] ?? null;

        return [
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : null,
            'content' => is_string($payload['content'] ?? null) ? $payload['content'] : '',
            'intent' => is_string($intent) && $intent !== '' ? $intent : 'note',
        ];
    }

    protected function body(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->required($this->content, 'content'),
            'intent' => $this->intent,
        ];
    }

    protected static function defaults(): array
    {
        return ['title' => null, 'intent' => 'note'];
    }

    /** The title, else the note itself: one line that still says what the row wants beside it. */
    protected function defaultFallback(): ?string
    {
        return $this->title ?? $this->content;
    }
}
