<?php

namespace Storyfeed\Support;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\StoryfeedManager;
use Storyfeed\Verb;

/**
 * Which roles, once tombstoned, make an activity redundant: the roles its
 * verb is ABOUT. A container singleton.
 *
 * Minimal version written by lane T3 against the T2/T3 contract (scratchpad
 * 301); lane T2 owns this class, and its implementation wins on rebase.
 */
class TombstoneRules
{
    /** The AS2 activity types whose object being gone is expected. */
    public const REMOVAL_TYPES = [ActivityType::Delete, ActivityType::Remove, ActivityType::Undo, ActivityType::Reject];

    /** @var array<string, list<string>> */
    private array $rules = [];

    /**
     * An explicit rule, keyed `type.verb` (wildcards allowed: `type.*`,
     * `*.verb`, `*.*`). It replaces the default set.
     *
     * @param  list<string>  $roles
     */
    public function set(string $key, array $roles): void
    {
        $this->rules[$key] = $roles;
    }

    /**
     * The explicit rule on the ladder (`type.verb` → `type.*` → `*.verb` →
     * `*.*`); otherwise none for a removal verb; otherwise the object.
     *
     * @return list<string>
     */
    public function constitutiveRoles(?string $type, string $verb): array
    {
        // Asking the verb registry compiles registered stories first, and
        // with them every `->missing()` still to be set here.
        $registered = app(StoryfeedManager::class)->activityType($verb);
        $type ??= '*';

        foreach (["{$type}.{$verb}", "{$type}.*", "*.{$verb}", '*.*'] as $key) {
            if (array_key_exists($key, $this->rules)) {
                return $this->rules[$key];
            }
        }

        return $this->isRemoval($registered, $verb) ? [] : ['object'];
    }

    protected function isRemoval(ActivityType|string|null $type, string $verb): bool
    {
        if (! $type instanceof ActivityType) {
            $type = ActivityType::tryFrom((string) $type) ?? Verb::tryFrom($verb)?->activityType();
        }

        return in_array($type, self::REMOVAL_TYPES, true);
    }
}
