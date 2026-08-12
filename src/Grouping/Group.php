<?php

namespace Storyfeed\Grouping;

use Storyfeed\Exceptions\StoryMisconfigured;

/**
 * One aggregate headline, attached to an axis — the `groups()` entry on a Story.
 *
 * This inverts the registry's ordering, which is the entire ergonomic win. The
 * `aggregateGrammar` array is keyed `{axis}.{verb}` and therefore reads by AXIS:
 * one consumer's 47 entries put a single verb's six headlines 40+ lines apart.
 * A Story's `groups()` collects them by VERB, so everything about one activity
 * type sits together. No new runtime concept is needed — the compiler simply
 * re-sorts the same keys.
 *
 * A Group ATTACHES a headline to an existing axis. It cannot declare or modify
 * one, and that is a structural limit rather than a simplification:
 *
 *   - axis registration order IS global curation priority, which no single verb
 *     can own;
 *   - CurateCluster reads eligibility per AXIS, never per verb, so the
 *     substrate cannot express a per-verb threshold;
 *   - Axis::pinnedTokens() derives token safety from one recipe, so per-story
 *     recipes would make the same axis name pin different tokens depending on
 *     which story compiled last.
 *
 * Axes stay in `Storyfeed::axes([Axis::make(...)])`. Which is also what the
 * evidence shows real apps want: one consumer registered exactly one axis
 * globally and then authored 47 headlines against the built-ins.
 */
final class Group
{
    protected ?string $headline = null;

    protected ?string $parentHeadline = null;

    private function __construct(
        public readonly string $axis,
    ) {}

    public static function on(string $axis): self
    {
        return new self($axis);
    }

    /** Many actors, one target — "Bob, Sally and 3 others uploaded files to X". */
    public static function byActors(): self
    {
        return new self('actors');
    }

    /** One actor, many targets — "Sally commented in 3 projects". */
    public static function byTargets(): self
    {
        return new self('targets');
    }

    /** One actor, one object, repeatedly — "Sally made 5 revisions to X". */
    public static function byObject(): self
    {
        return new self('object');
    }

    /** The fallback axis: same actor repeating the same act. */
    public static function repeat(): self
    {
        return new self('repeat');
    }

    /** An authored collection story (see Contracts\Collectable). */
    public static function composite(): self
    {
        return new self('composite');
    }

    /** Every axis — the `*.{verb}` aggregate key. */
    public static function any(): self
    {
        return new self('*');
    }

    public function headline(string $template): self
    {
        $this->headline = $template;

        return $this;
    }

    /**
     * The SINGULAR headline for a composite's parent activity.
     *
     * A composite parent has no object of its own — the collection is the
     * object — so the verb's normal `{type}.{verb}` grammar key never resolves
     * for it, and it needs `*.{verb}` instead. That second, unlisted registry
     * is a trap a consumer found only from doctor output after following every
     * documented step; declaring it here means the compiler can refuse to
     * compile a composite without it.
     *
     * It lives on Group rather than Story deliberately, even though it writes
     * to the singular registry: everything about the composite stays on one
     * line, so the two entries cannot drift apart.
     */
    public function parentHeadline(string $template): self
    {
        $this->parentHeadline = $template;

        return $this;
    }

    /**
     * NOT IMPLEMENTED, and defined only to say why.
     *
     * `Group::byActors()->min(3)` was in the original sketch and cannot work.
     * Eligibility is a property of the axis, evaluated globally by the curator,
     * so a per-story minimum could only be (a) silently ignored, or (b) a
     * silent mutation of the shared axis by whichever story compiled last —
     * bleeding one verb's threshold into every other verb. Both are the
     * quiet-failure class this package removed magic to escape, so the loud
     * undefined behaviour is the feature.
     */
    public function min(int $count): never
    {
        throw new StoryMisconfigured(
            'Group::min() does not exist: eligibility belongs to the AXIS, not to one story\'s headline. '
            ."Set it once where the axis is declared:\n\n"
            ."    Storyfeed::axes([\n"
            ."        Axis::make('{$this->axis}')->eligibleWhenDistinct('actor', min: {$count}),\n"
            ."    ]);\n\n"
            .'A per-story minimum would either be ignored or would silently change the threshold for every '
            .'other verb on this axis.'
        );
    }

    public function template(): ?string
    {
        return $this->headline;
    }

    public function parentTemplate(): ?string
    {
        return $this->parentHeadline;
    }
}
