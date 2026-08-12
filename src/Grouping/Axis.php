<?php

namespace Storyfeed\Grouping;

use Closure;
use InvalidArgumentException;
use Storyfeed\Models\Activity;

/**
 * One grouping axis, fully declared in one place: its key recipe, when it
 * applies, when it is eligible to win curation, and (derived) which
 * headline tokens it pins.
 *
 *   Axis::make('object')
 *       ->key('aa:aid:v:oa!:oid!:d')
 *       ->eligibleWhenMembers(min: 2);
 *
 * Recipes use the minified token DSL (Field::TOKENS); `!` marks a field
 * the activity must have for the axis to apply. The recipe compiles to
 * two bitmasks at registration — parse errors throw here, and every
 * downstream question (does this axis apply? is :object safe here?) is
 * mask algebra over Field bits.
 *
 * Registration order is priority (docs/grouping.md); the fallback axis
 * wins when no aggregate axis is eligible. Eligibility is declarative
 * data, interpreted by CurateCluster — introspectable, no hidden closures.
 */
class Axis
{
    protected int $fields = 0;

    protected int $required = 0;

    protected ?Closure $custom = null;

    /** @var array<int, string> manually declared pins (closure recipes only) */
    protected array $declaredPins = [];

    protected bool $fallback = false;

    protected bool $rowBacked = false;

    /**
     * @var array<int, array{distinct?: string, members?: int, min?: int}>
     */
    protected array $eligibility = [];

    /** Keys longer than this are digested — silent truncation over-groups. */
    protected const DIGEST_THRESHOLD = 200;

    final protected function __construct(public readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * The key recipe: a minified token string ('aa:aid:v:oa!:oid!:d'), or
     * a closure for exotic keys (which must then declare pins manually).
     */
    public function key(string|Closure $recipe): static
    {
        if ($recipe instanceof Closure) {
            $this->custom = $recipe;

            return $this;
        }

        [$this->fields, $this->required] = $this->compile($recipe);

        return $this;
    }

    /**
     * Eligible when the cluster has at least $min distinct values of a
     * role ('actor' | 'target' | 'object' | 'context').
     */
    public function eligibleWhenDistinct(string $role, int $min): static
    {
        $this->eligibility[] = ['distinct' => $role, 'min' => $min];

        return $this;
    }

    /**
     * Eligible when the cluster has at least $min member activities.
     */
    public function eligibleWhenMembers(int $min): static
    {
        $this->eligibility[] = ['members' => $min];

        return $this;
    }

    /**
     * The axis chosen when no aggregate axis is eligible. Exactly one
     * fallback should be registered; it needs no eligibility rules.
     */
    public function fallback(bool $fallback = true): static
    {
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * Manually declare pinned tokens — required for closure recipes, where
     * homogeneity cannot be derived.
     */
    public function pins(string ...$tokens): static
    {
        $this->declaredPins = array_values($tokens);

        return $this;
    }

    /**
     * A row-backed bucket: membership is declared or detected state written
     * by the package (batch windows, composite claims), never inferred by
     * the strategy and never competed for by curation. Registered so pins,
     * coverage audits and token validation still apply.
     */
    public function rowBacked(bool $rowBacked = true): static
    {
        $this->rowBacked = $rowBacked;

        return $this;
    }

    public function isRowBacked(): bool
    {
        return $this->rowBacked;
    }

    public function isFallback(): bool
    {
        return $this->fallback;
    }

    /**
     * @return array<int, array{distinct?: string, members?: int, min?: int}>
     */
    public function eligibility(): array
    {
        return $this->eligibility;
    }

    /**
     * The activity's key on this axis, or null when the axis does not
     * apply (a required field is missing). Long keys are digested —
     * fixed-width, still derived, still recomputable.
     */
    public function hashFor(Activity $activity): ?string
    {
        // Row-backed buckets are written by the package's own actions,
        // never derived from activity fields.
        if ($this->rowBacked) {
            return null;
        }

        $key = $this->assemble($activity);

        if ($key === null) {
            return null;
        }

        return strlen($key) > self::DIGEST_THRESHOLD ? sha1($key) : $key;
    }

    /**
     * The headline tokens this axis can honestly serve: the universal
     * aggregate tokens plus every role whose identity PAIR is in the field
     * mask — homogeneity by construction, not by hand-maintained list.
     *
     * @return array<int, string>
     */
    public function pinnedTokens(): array
    {
        if ($this->custom !== null || $this->rowBacked) {
            return [...$this->declaredPins, ':actors', ':count', ':others'];
        }

        $pinned = [];

        foreach (Field::PINNABLE as $token => $pair) {
            if (($this->fields & $pair) === $pair) {
                $pinned[] = $token;
            }
        }

        return [...$pinned, ':actors', ':count', ':others'];
    }

    protected function assemble(Activity $activity): ?string
    {
        if ($this->custom !== null) {
            $key = ($this->custom)($activity);

            return $key === null ? null : (string) $key;
        }

        foreach (Field::CANONICAL_ORDER as $field) {
            if (($this->required & $field->value) !== 0 && ! $field->isFilledOn($activity)) {
                return null;
            }
        }

        $parts = [];

        foreach (Field::CANONICAL_ORDER as $field) {
            if (($this->fields & $field->value) !== 0) {
                $parts[] = $field->valueFor($activity);
            }
        }

        return implode(':', $parts);
    }

    /**
     * @return array{0: int, 1: int} [fields mask, required mask]
     */
    protected function compile(string $recipe): array
    {
        $fields = 0;
        $required = 0;

        foreach (explode(':', $recipe) as $token) {
            $isRequired = str_ends_with($token, '!');
            $bare = rtrim($token, '!');

            $field = Field::TOKENS[$bare] ?? null;

            if ($field === null) {
                throw new InvalidArgumentException(
                    "Unknown recipe token [{$bare}] in axis [{$this->name}]. "
                    .'Known tokens: '.implode(' ', array_keys(Field::TOKENS)).'.',
                );
            }

            $fields |= $field->value;

            if ($isRequired) {
                $required |= $field->value;
            }
        }

        return [$fields, $required];
    }
}
