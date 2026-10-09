<?php

namespace Storyfeed\Support;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ReflectionClass;

/** Resolves type-filter inputs to the morph classes stored on activities. */
final class RoleTypes
{
    /**
     * @param  Model|string|list<Model|string>  $types
     * @return list<string>
     */
    public static function resolve(string $role, Model|string|array $types): array
    {
        $types = is_array($types) ? $types : [$types];

        if ($types === []) {
            throw new InvalidArgumentException("{$role}Type() was given an empty list of types.");
        }

        $resolved = [];

        foreach ($types as $type) {
            $model = $type;

            if (is_string($type)) {
                $class = MorphResolver::classFor($type);

                if ($class === null || ! is_subclass_of($class, Model::class) || ! (new ReflectionClass($class))->isInstantiable()) {
                    throw new InvalidArgumentException("{$role}Type() cannot resolve type [{$type}].");
                }

                $model = new $class;
            }

            if (! $model instanceof Model) {
                throw new InvalidArgumentException("{$role}Type() requires model instances, model classes or morph aliases; got [".get_debug_type($type).'].');
            }

            try {
                $resolved[] = $model->getMorphClass();
            } catch (ClassMorphViolationException $exception) {
                $value = is_string($type) ? $type : $type::class;
                throw new InvalidArgumentException("{$role}Type() cannot resolve stored morph type [{$value}].", previous: $exception);
            }
        }

        return array_values(array_unique($resolved));
    }
}
