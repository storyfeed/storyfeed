<?php

namespace Storyfeed\Support;

use BackedEnum;
use InvalidArgumentException;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * The verb allowlist/denylist behind FeedBuilder::only() and ::except().
 *
 * @internal Not contract. The public surface is only()/except() and the shape
 * of the `feeds` findings; this class exists so the pattern semantics have ONE
 * implementation — the read path applies them as SQL, and the FeedCoverage
 * check reads the same patterns back to decide whether a verb was classified.
 * Two implementations of "does `order.*` cover `order.paid`" would drift, and
 * the drift would be a doctor check that says a verb is safe when it is not.
 *
 * Constraints accumulate and only ever NARROW: every only()/except() call adds
 * an independent rule the verb must satisfy, so two allowlists intersect rather
 * than union. That is what makes "a preset never widens" structural — a call
 * site that adds ->only() on top of Storyfeed::feed('customer') can only cut
 * further, never open the feed back up.
 */
final class VerbFilter
{
    /** @var list<array{allow: bool, patterns: list<string>}> */
    protected array $rules = [];

    /**
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public function allow(array|string|FeedVerb|BackedEnum $verbs): static
    {
        $this->rules[] = ['allow' => true, 'patterns' => $this->normalize($verbs, 'only')];

        return $this;
    }

    /**
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public function deny(array|string|FeedVerb|BackedEnum $verbs): static
    {
        $this->rules[] = ['allow' => false, 'patterns' => $this->normalize($verbs, 'except')];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * Every pattern this filter names, in either direction. What FeedCoverage
     * asks: being DENIED counts as classified — somebody decided.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn (array $rule) => $rule['patterns'], $this->rules),
        )));
    }

    /** The literal (non-wildcard) verbs named, which are the ones a typo shows up in. */
    /** @return list<string> */
    public function literals(): array
    {
        return array_values(array_filter(
            $this->patterns(),
            fn (string $pattern) => ! str_ends_with($pattern, '*'),
        ));
    }

    /** Whether any pattern names this verb, in either direction. */
    public function mentions(string $verb): bool
    {
        foreach ($this->patterns() as $pattern) {
            if ($this->matches($pattern, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this filter would ADMIT the verb — the PHP twin of applyTo()'s
     * SQL, rule for rule: an allow rule passes if any of its patterns matches,
     * a deny rule passes if none does, and rules AND together because they
     * only ever narrow.
     *
     * Distinct from mentions(), and the distinction is the whole point of the
     * testing surface built on this: mentions() asks "did anyone DECIDE about
     * this verb", which is doctor's question, and being denied counts. This
     * asks "would this feed SHOW it", which is the app's question.
     *
     * A parity test asserts this agrees with applyTo() over the same rows —
     * two implementations of "does `order.*` admit `order.paid`" would drift,
     * and the drift would be a test that passes while the feed leaks.
     */
    public function admits(string $verb): bool
    {
        foreach ($this->rules as $rule) {
            $matched = false;

            foreach ($rule['patterns'] as $pattern) {
                if (self::matches($pattern, $verb)) {
                    $matched = true;

                    break;
                }
            }

            if ($matched !== $rule['allow']) {
                return false;
            }
        }

        return true;
    }

    public static function matches(string $pattern, string $verb): bool
    {
        if (! str_ends_with($pattern, '*')) {
            return $pattern === $verb;
        }

        return str_starts_with($verb, substr($pattern, 0, -1));
    }

    /**
     * Applied at FeedBuilder::filteredActivities(), the one query every branch
     * of the read is built from — so group counts and the distinct-role counts
     * behind ":actors and 3 others" are computed INSIDE the filter, not over
     * the whole table. An allowlist that leaked "and 3 others" from excluded
     * verbs would be worse than no allowlist.
     */
    public function applyTo(ActivityBuilder $query): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $column = $grammar->wrap($query->getModel()->getTable().'.verb');

        foreach ($this->rules as $rule) {
            $query->where(function (ActivityBuilder $group) use ($rule, $column) {
                foreach ($rule['patterns'] as $pattern) {
                    $rule['allow']
                        ? $this->orMatch($group, $column, $pattern)
                        : $this->andNotMatch($group, $column, $pattern);
                }
            });
        }
    }

    protected function orMatch(ActivityBuilder $query, string $column, string $pattern): void
    {
        str_ends_with($pattern, '*')
            ? $query->orWhereRaw($this->likeExpression($column), [$this->like($pattern)])
            : $query->orWhereRaw("{$column} = ?", [$pattern]);
    }

    protected function andNotMatch(ActivityBuilder $query, string $column, string $pattern): void
    {
        str_ends_with($pattern, '*')
            ? $query->whereRaw('not '.$this->likeExpression($column), [$this->like($pattern)])
            : $query->whereRaw("{$column} <> ?", [$pattern]);
    }

    /**
     * The explicit `escape` clause is the whole point of dropping to raw SQL
     * here. SQLite has NO default LIKE escape character, so the escaping below
     * would be inert there and `a%b.*` would match `axb.leak` — a wildcard
     * silently WIDENING an allowlist, which is the one direction a safety
     * filter must never fail in. Declaring it is standard SQL and portable
     * across the drivers this package supports.
     */
    protected function likeExpression(string $column): string
    {
        return "{$column} like ? escape '\\'";
    }

    /**
     * `order.*` becomes `order.%`. The prefix is escaped so a verb vocabulary
     * containing `%`, `_` or a backslash — all legal, since verbs are free-form
     * strings — cannot turn into an accidental wildcard that widens the
     * allowlist.
     */
    protected function like(string $pattern): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], substr($pattern, 0, -1)).'%';
    }

    /** One verb, however it was written, as the string storage uses. */
    public static function verbString(string|FeedVerb|BackedEnum $verb): string
    {
        return match (true) {
            $verb instanceof FeedVerb => $verb->verb(),
            $verb instanceof BackedEnum => (string) $verb->value,
            default => $verb,
        };
    }

    /**
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     * @return list<string>
     */
    protected function normalize(array|string|FeedVerb|BackedEnum $verbs, string $method): array
    {
        $verbs = is_array($verbs) ? $verbs : [$verbs];

        $normalized = array_map(self::verbString(...), $verbs);

        // An empty list is technically honest — an empty allowlist matches
        // nothing — but its symptom is a feed that renders as "nothing happened
        // yet", which reads as a data problem for as long as it takes someone
        // to find the empty config that caused it. Loud beats plausible.
        if ($normalized === []) {
            throw new InvalidArgumentException(
                "FeedBuilder::{$method}() was given an empty list of verbs. An empty allowlist "
                .'matches nothing and renders as an empty feed; drop the call instead.',
            );
        }

        return $normalized;
    }
}
