<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a publish fills a role with a type its verb's constraint
 * doesn't allow: `->whereActor(User::class)` and a Team acted. A route
 * whose `->where()` constraint fails doesn't match; a story whose role
 * constraint fails doesn't publish. The message takes StoryObjectMismatch's
 * shape, itself `UrlGenerationException::forMissingParameters()`'s.
 */
class StoryRoleMismatch extends InvalidArgumentException
{
    /**
     * @param  list<string>  $expected
     */
    public static function forRole(string $verb, string $key, string $role, array $expected, string $given): self
    {
        return new self(
            "Wrong {$role} for [Verb: {$verb}] [Key: {$key}] [Expected: ".implode(', ', $expected)."] [Given: {$given}]. "
            .'The verb\'s '.(in_array($role, ['actor', 'object', 'target', 'context'], true) ? '->where'.ucfirst($role).'()' : "->whereRole('{$role}', …)")
            ." says which types may be its {$role}"
            .($role === 'actor' ? "; add 'party' to allow a Party, or publish ->anonymously() when nobody is known." : '.'),
        );
    }
}
