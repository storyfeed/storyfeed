<?php

namespace Storyfeed;

use Closure;
use Storyfeed\Support\ActivityRoles;

/**
 * A headline registered as a translation key, translated when the feed is
 * READ — in the reader's locale, not the locale the app booted in.
 *
 *     Story::for(Order::class)->verb('place')
 *         ->headline(FeedHeadline::trans('feed.order_placed'));
 *
 *     Storyfeed::grammar(['order.place' => FeedHeadline::trans('feed.order_placed')]);
 *
 * WHY NOT `__()` AT REGISTRATION. Definitions run at boot, and boot runs
 * before the locale middleware: `__('feed.order_placed')` in a provider is
 * translated once, in the default locale, and every reader gets that
 * language. Under `storyfeed:cache` it would be the locale of whoever ran the
 * deploy. The wrapper stores the key and defers the lookup to the one moment
 * the reader's locale is known. It is the twin of {@see FeedNoun::trans()}.
 *
 * The translated line is a template like any other: its role tokens stay
 * tokens (and links), and its optional segments are resolved per activity.
 */
final class FeedHeadline
{
    private function __construct(
        public readonly string $key,
    ) {}

    /** A translation key, translated in the reader's locale when the feed is read. */
    public static function trans(string $key): self
    {
        return new self($key);
    }

    /**
     * The template in the current locale. A missing key renders as the key,
     * which is what `__()` does and what makes the gap visible on screen.
     */
    public function toTemplate(): string
    {
        $line = __($this->key);

        return is_string($line) ? $line : $this->key;
    }

    /**
     * var_export() support, so a compiled manifest can hold the key and
     * translate it at read time exactly as the uncached path does.
     *
     * @param  array{key: string}  $state
     *
     * @internal
     */
    public static function __set_state(array $state): self
    {
        return new self($state['key']);
    }

    /**
     * Whether a string names at least one role token (`:actor`, `:object`, …).
     *
     * This is how a closure headline's result is read: a string with a role
     * token is a TEMPLATE (the renderer links the names), and one without is
     * finished text.
     *
     * @internal
     */
    public static function hasRoleTokens(string $text): bool
    {
        preg_match_all('/:([a-z]+)/', $text, $matches);

        foreach ($matches[1] as $token) {
            if (in_array(self::role($token), ActivityRoles::PAYLOAD, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve optional segments: `':actor placed :object[ with :target]'`.
     *
     * A bracketed segment is dropped when a role it names is empty, and kept
     * (without its brackets) otherwise, so the template that reaches the
     * payload never contains a bracket. A segment that names no role is
     * always kept. Plural tokens (`:actors`) belong to their role, and
     * non-role tokens (`:count`, `:others`) never drop a segment.
     *
     * Segments do not nest: `[` and `]` inside a segment are not special.
     *
     * @param  Closure(string): bool  $filled  whether a role (singular name) holds anything
     *
     * @internal
     */
    public static function resolveSegments(string $template, Closure $filled): string
    {
        if (! str_contains($template, '[')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\[([^\[\]]*)\]/',
            function (array $match) use ($filled): string {
                preg_match_all('/:([a-z]+)/', $match[1], $tokens);

                foreach ($tokens[1] as $token) {
                    $role = self::role($token);

                    if (in_array($role, ActivityRoles::PAYLOAD, true) && ! $filled($role)) {
                        return '';
                    }
                }

                return $match[1];
            },
            $template,
        );
    }

    /** `actors` → `actor`; anything else unchanged. */
    private static function role(string $token): string
    {
        $singular = str_ends_with($token, 's') ? substr($token, 0, -1) : $token;

        return in_array($singular, ActivityRoles::PAYLOAD, true) ? $singular : $token;
    }
}
