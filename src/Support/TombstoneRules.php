<?php

namespace Storyfeed\Support;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\StoryfeedManager;
use Storyfeed\Verb;

/**
 * Which roles, when tombstoned, make an activity redundant: the fact the
 * payload's `redundant` key reports (docs/payload.md, tombstones).
 *
 * A role is CONSTITUTIVE for a verb when the verb's whole news is about it:
 * "Dana placed Order #1042" says nothing once the order is gone, though it
 * is still true as history. Every other role is incidental, and a tombstone
 * there changes a noun, not the story ("a former customer placed …").
 *
 * The answer, for an activity of one object type and verb:
 *
 *  1. An explicit rule, on the type → verb ladder (`order.place` →
 *     `order.*` → `*.place` → `*.*`), set by `->missing()` on a verb
 *     definition. A rule replaces the default set; an empty one means no
 *     role is constitutive.
 *  2. Otherwise, a REMOVAL VERB answers `[]`: a verb whose AS2 type is
 *     Delete, Remove, Undo or Reject records the removal itself, so its
 *     object being gone is expected, not news. The registered type wins; a
 *     verb nobody registered falls back to the Storyfeed\Verb case of the
 *     same name, so `archive` recorded as a plain string still counts.
 *  3. Otherwise `['object']`, the shipped default.
 *
 * Only a fact: core never writes "removed". A renderer reads `redundant` to
 * choose the whole-activity reading over the role-level one.
 *
 * A container singleton. Rules arrive from CompileStories at boot, and the
 * types are the deleted models' own aliases (a tombstone's `formerType`),
 * never `storyfeed.tombstone`.
 */
final class TombstoneRules
{
    /** The AS2 types whose object is removed by the activity itself. */
    public const REMOVALS = [ActivityType::Delete, ActivityType::Remove, ActivityType::Undo, ActivityType::Reject];

    /** @var array<string, list<string>> `type.verb` (wildcards allowed) => roles */
    private array $rules = [];

    public function __construct(
        private readonly StoryfeedManager $storyfeed,
    ) {}

    /**
     * The roles that, tombstoned, make an activity of this type and verb
     * redundant.
     *
     * @param  string|null  $type  the object's morph alias (for a tombstoned object, its former alias)
     * @return list<string>
     */
    public function constitutiveRoles(?string $type, string $verb): array
    {
        // First, because asking the manager compiles the stories, and
        // compiling is what sets the explicit rules read below.
        $removal = $this->isRemoval($verb);
        $type ??= '*';

        foreach (["{$type}.{$verb}", "{$type}.*", "*.{$verb}", '*.*'] as $key) {
            if (array_key_exists($key, $this->rules)) {
                return $this->rules[$key];
            }
        }

        return $removal ? [] : ['object'];
    }

    /**
     * Declare the constitutive roles for a `type.verb` key. Replaces
     * whatever the key had; an empty list means none.
     *
     * @param  list<string>  $roles
     */
    public function set(string $key, array $roles): void
    {
        $this->rules[$key] = array_values(array_unique($roles));
    }

    /**
     * Whether a verb records a removal: its AS2 type is Delete, Remove, Undo
     * or Reject.
     */
    public function isRemoval(string $verb): bool
    {
        $type = $this->storyfeed->activityType($verb);

        // A type registered as its wire string reads as the enum case.
        if (is_string($type)) {
            $type = ActivityType::tryFrom($type);
        }

        $type ??= Verb::tryFrom($verb)?->activityType();

        return in_array($type, self::REMOVALS, true);
    }

    /**
     * Every explicit rule, keyed `type.verb`.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
