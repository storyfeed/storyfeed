<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a Feed class, or a call site entering one, is wrong.
 *
 * Every case here is a failure that would otherwise be SILENT and plausible: an
 * unscoped customer feed, a subject bound to the wrong model, a call site
 * quietly rebinding the scope its class declared. None of them look like bugs
 * in a rendered feed, which is why they are exceptions rather than findings.
 */
class FeedMisconfigured extends InvalidArgumentException
{
    /**
     * A subject feed reached by NAME. There is no unscoped way to build one:
     * the subject is a constructor argument precisely so that PHP, rather than
     * this package, is what refuses.
     */
    public static function requiresArguments(string $feed, string $name): self
    {
        return new self(
            "Feed [{$feed}] takes constructor arguments, so it cannot be built from the name "
            ."'{$name}' alone — an unscoped build would render every row its allowlist admits, "
            .'which is what taking a subject exists to prevent. Enter it through its constructor: '
            .class_basename($feed).'::make($subject). Registering it by name is still worth doing: '
            .'that is what lets storyfeed:doctor check its verbs.'
        );
    }

    /**
     * The backstop for a hand-written feed. A generated one binds its subject
     * in scope(); a hand-written one that takes an order and never uses it is
     * an unscoped feed that LOOKS scoped at every call site — the failure this
     * layer exists to remove, wearing the layer's own clothes.
     */
    public static function unscoped(string $feed): self
    {
        return new self(
            "Feed [{$feed}] takes constructor arguments but its scope() binds no role, so nothing "
            .'it was given reaches the query — every call site would read as scoped while the feed '
            .'returned the whole table. Bind it: protected function scope(FeedBuilder $feed): void '
            .'{ $feed->context($this->order); }'
        );
    }

    /**
     * A declaration saying two things. The read path would apply the filter
     * and doctor would believe the word, and the person reading the feed
     * class could not tell which one the surface actually does.
     *
     * @param  list<string>  $patterns
     */
    public static function unrestrictedButFiltered(array $patterns): self
    {
        return new self(
            'unrestricted() was called on a feed that already declares only()/except() ('
            .implode(', ', $patterns).'). A feed is restricted or it is not: drop the verb '
            .'constraint if this really is the world feed, or drop unrestricted() if it is not. '
            .'Narrowing a declared-unrestricted feed at a call site is still fine — only the '
            .'declaration itself may not contradict itself.'
        );
    }

    /**
     * Rebinding a Feed's scope at the call site.
     *
     * The verb allowlist is unwidenable for free — only(A) then only(B) is
     * A ∩ B — but role filters are single-slot assignments, so a second
     * involving() REPLACES the first. Scope has no narrowing property of its
     * own, so it gets a lock instead.
     */
    public static function scopeLocked(string $role, string $owner): self
    {
        return new self(
            "This feed's scope is bound by {$owner} (->{$role}()) and cannot be rebound at the "
            .'call site. Narrow it further — query(), only(), or another role — but the subject is '
            .'the feed\'s. Rebinding it would silently swap the scope a surface was built on, and '
            .'no allowlist protects you from that.'
        );
    }

    public static function notAFeed(string $class): self
    {
        return new self(
            "[{$class}] was registered as a feed but does not extend Storyfeed\\Feed."
        );
    }
}
