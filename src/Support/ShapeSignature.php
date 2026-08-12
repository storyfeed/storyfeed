<?php

namespace Storyfeed\Support;

use Storyfeed\Contracts\HasFeedShapeVersion;
use Storyfeed\FeedEntity;

/**
 * Fingerprints the SHAPE of a snapshot — the structure today's toFeed()
 * produces, not its values — so stored snapshots can be compared against
 * current code without inspecting the code. This is what detects a changed
 * DTO inside FeedEntity::data, which source-hashing toFeed() cannot see:
 * the fingerprint is of the output, wherever the shape came from.
 *
 * Ingredients: the component name (a renderer-selecting value — part of
 * shape), the model's declared feedShapeVersion (default 1; the semantic
 * escape hatch), and the recursive sorted key-paths of `data` with scalar
 * type tags. Values are excluded — they differ per row legitimately, and a
 * label FORMAT change is exactly what the declared version exists for.
 */
class ShapeSignature
{
    /**
     * @param  class-string  $class  the Feedable model class
     */
    public static function for(FeedEntity $entity, string $class): string
    {
        $version = is_a($class, HasFeedShapeVersion::class, true)
            ? $class::feedShapeVersion()
            : 1;

        return sha1(json_encode([
            $entity->component,
            $version,
            self::keyPaths($entity->data ?? []),
        ]));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<int, string> sorted "path.to.key:type" entries
     */
    protected static function keyPaths(array $data, string $prefix = ''): array
    {
        $paths = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && ! array_is_list($value)) {
                $paths = [...$paths, ...self::keyPaths($value, $path)];

                continue;
            }

            // Lists are one shape slot (their length varies per row);
            // scalars carry a type tag so int→string drifts register.
            $paths[] = $path.':'.get_debug_type($value);
        }

        sort($paths);

        return $paths;
    }
}
