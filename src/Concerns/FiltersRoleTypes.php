<?php

namespace Storyfeed\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Filter any role by a model class, instance or morph alias.
 * Lists match any listed type; repeated calls narrow with AND. Feed-class
 * subject locks apply to both the record filter and its type filter.
 * Strings in record filters remain reserved for parties.
 */
trait FiltersRoleTypes
{
    /** @param  Model|string|list<Model|string>  $types */
    public function actorType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('actor', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function objectType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('object', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function targetType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('target', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function contextType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('context', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function originType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('origin', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function resultType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('result', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function instrumentType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('instrument', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function locationType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('location', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    public function generatorType(Model|string|array $types): static
    {
        return $this->whereRoleTypes('generator', $types);
    }

    /** @param  Model|string|list<Model|string>  $types */
    abstract protected function whereRoleTypes(string $role, Model|string|array $types): static;
}
