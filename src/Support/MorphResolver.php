<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Party;

/**
 * Resolves a stored morph alias back to its model class.
 *
 * Package-owned aliases resolve independently of the application's morph
 * map. That is not a convenience: an app calling Relation::enforceMorphMap()
 * without our aliases would otherwise leave them unresolvable, and
 * TrickleSnapshots treats an unresolvable role as an orphan and deletes the
 * activity. Package models must never depend on the host app's map.
 */
class MorphResolver
{
    /**
     * The class for a morph alias, or null when it cannot be resolved.
     *
     * @return class-string|null
     */
    public static function classFor(string $alias): ?string
    {
        if ($class = self::packageAliases()[$alias] ?? null) {
            return $class;
        }

        return Relation::getMorphedModel($alias) ?? (class_exists($alias) ? $alias : null);
    }

    /**
     * Resolve a morph alias + key to a Feedable model instance.
     *
     * @return (Model&Feedable)|null
     */
    public static function feedable(string $alias, int|string $id): ?Model
    {
        $class = self::classFor($alias);

        if ($class === null || ! is_a($class, Model::class, true) || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        /** @var (Model&Feedable)|null guarded by the is_a checks above */
        return $class::query()->find($id);
    }

    /**
     * Whether an alias belongs to a class implementing the given interface.
     *
     * @param  class-string  $contract
     */
    public static function implements(string $alias, string $contract): bool
    {
        $class = self::classFor($alias);

        return $class !== null && is_a($class, $contract, true);
    }

    /**
     * Aliases owned by the package, resolvable regardless of the app's map.
     *
     * @return array<string, class-string>
     */
    protected static function packageAliases(): array
    {
        $party = config('storyfeed.models.party', Party::class);

        return [
            config('storyfeed.morph_alias', 'storyfeed.party') => $party,
        ];
    }
}
