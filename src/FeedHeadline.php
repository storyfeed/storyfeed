<?php

namespace Storyfeed;

use Closure;
use Illuminate\Support\Str;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Bindings;
use Stringable;

/**
 * A headline registered as a translation key, translated when the feed is
 * READ — in the reader's locale, not the locale the app booted in.
 *
 *     Story::for(Task::class)->verb('complete')
 *         ->headline(FeedHeadline::trans('feed.task_completed'));
 *
 * WHY NOT `__()` AT REGISTRATION. Definitions run at boot, and boot runs
 * before the locale middleware: `__('feed.task_completed')` in a provider is
 * translated once, in the default locale, and every reader gets that
 * language. Under `storyfeed:cache` it would be the locale of whoever ran the
 * deploy. The wrapper stores the key and defers the lookup to the one moment
 * the reader's locale is known. It is the twin of {@see FeedNoun::trans()}.
 *
 * The translated line is a template like any other: its role tokens stay
 * tokens (and links), and its optional segments are resolved per activity.
 *
 * RENDER-TIME BINDINGS. A headline closure may return a headline with literal
 * values bound to its tokens, supplied when the feed is read and never
 * stored:
 *
 *     Story::for(Invoice::class)->verb('send')->headline(
 *         fn (ActivityContext $c) => FeedHeadline::make(':actor sent :object to :recipient')
 *             ->bind('recipient', $c->object?->recipientName()),
 *     );
 *
 * They merge with the activity's stored bindings (PendingActivity::bind()),
 * and a render-time value wins on a name conflict.
 */
final class FeedHeadline
{
    /**
     * @param  array<string, string>  $bindings
     */
    private function __construct(
        public readonly string $key,
        private readonly bool $translate = true,
        private readonly array $bindings = [],
    ) {}

    /** A translation key, translated in the reader's locale when the feed is read. */
    public static function trans(string $key): self
    {
        return new self($key);
    }

    /** A literal template, used as written: `':actor sent :object to :recipient'`. */
    public static function make(string $template): self
    {
        return new self($template, translate: false);
    }

    /**
     * Bind a literal value to a token when the headline is read, as
     * PendingActivity::bind() does when an activity is recorded, but stored
     * nowhere. The value prints exactly as given: no segments, tokens or
     * markup are read from it. It merges with the activity's stored
     * bindings and wins on a name conflict.
     *
     * A name that is a role (`actor`, `objects`, …), `count`, `others` or
     * `verb` throws, as does one that is not lowercase letters.
     */
    public function bind(string $name, string|int|float|Stringable $value): self
    {
        return new self($this->key, $this->translate, [
            ...$this->bindings,
            Bindings::name($name) => Bindings::value($value),
        ]);
    }

    /**
     * A stable description for manifest fingerprints and listings:
     * `trans:feed.sent`, or the literal template, with any bindings.
     *
     * @internal
     */
    public function describe(): string
    {
        $base = $this->translate ? "trans:{$this->key}" : $this->key;

        return $this->bindings === [] ? $base : $base.' '.json_encode($this->bindings);
    }

    /**
     * The render-time bindings, name => text.
     *
     * @return array<string, string>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /** Count-one English templates use "1 time", "1 approval", "1 item". */
    public static function forCount(string $template, int $count): string
    {
        if ($count !== 1) {
            return $template;
        }

        return preg_replace_callback('/(:count\s+)([\p{L}-]+)/u', fn (array $match) => $match[1].Str::singular($match[2]), $template) ?? $template;
    }

    /**
     * The template in the current locale. A missing key renders as the key,
     * which is what `__()` does and what makes the gap visible on screen.
     */
    public function toTemplate(): string
    {
        if (! $this->translate) {
            return $this->key;
        }

        $line = __($this->key);

        return is_string($line) ? $line : $this->key;
    }

    /**
     * var_export() support, so a compiled manifest can hold the key and
     * translate it at read time exactly as the uncached path does.
     *
     * @param  array{key: string, translate?: bool, bindings?: array<string, string>}  $state
     *
     * @internal
     */
    public static function __set_state(array $state): self
    {
        return new self($state['key'], $state['translate'] ?? true, $state['bindings'] ?? []);
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
