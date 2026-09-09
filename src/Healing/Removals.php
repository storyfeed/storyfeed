<?php

namespace Storyfeed\Healing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;
use Throwable;

/**
 * Was a story on this key deliberately removed?
 *
 * The key is verb + object type + object id — the identity `->replace()`
 * supersedes on. Absence of a row on a key is ambiguous: deleted on purpose,
 * or never recorded. Pruning force-deletes soft-deleted rows, so the row is
 * not the evidence; the `feed_removals` table is. Every removal path in the
 * package writes it, and `storyfeed:prune` never sweeps it.
 *
 * Two answers, kept separate because they have different shapes:
 *
 * - removed(): per-key evidence, written when the LAST live story on a key
 *   leaves. Supersession never writes it — the successor is a live sibling.
 * - prunedBefore(): the retention watermark, one row in feed_meta advanced
 *   by every prune sweep. A story published before it is absent because it
 *   expired, and a healer must not re-derive it.
 *
 * Neither is consulted by HealFeed yet. The healer can ask; it does not.
 */
final class Removals
{
    /** @internal The feed_meta key behind prunedBefore(). */
    public const PRUNED_BEFORE = 'removals:pruned_before';

    /**
     * Evidence that the last live story on this key was removed, or null when
     * none was recorded — or when a live story exists on the key now, which
     * makes any earlier removal moot. Two indexed lookups.
     *
     * Pass the object model, or its morph alias and key. An activity without
     * an object has no key and can carry no evidence: asking for one throws.
     */
    public static function removed(string $verb, Model|string $object, int|string|null $objectId = null): ?Removal
    {
        [$type, $id] = self::key($object, $objectId);

        $model = self::activity();

        $live = $model->newQuery()
            ->where('verb', $verb)
            ->where('object_type', $type)
            ->where('object_id', $id)
            ->exists();

        if ($live) {
            return null;
        }

        $row = self::query()
            ->where('verb', $verb)
            ->where('object_type', $type)
            ->where('object_id', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return new Removal(
            $row->verb,
            $row->object_type,
            $row->object_id,
            CarbonImmutable::parse($row->removed_at),
            $row->published_at === null ? null : CarbonImmutable::parse($row->published_at),
        );
    }

    /**
     * The retention watermark: every activity published before this instant
     * has been force-deleted by `storyfeed:prune`, whatever its key. Null
     * when no sweep has run since the watermark existed, and null when the
     * meta table is missing (an adopter mid-upgrade) — reads degrade.
     */
    public static function prunedBefore(): ?CarbonImmutable
    {
        try {
            $value = Meta::query()->where('key', self::PRUNED_BEFORE)->value('value');
        } catch (Throwable) {
            return null;
        }

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @internal The evidence table, on the activity model's connection.
     */
    public static function query(): Builder
    {
        return self::activity()->getConnection()->table(config('storyfeed.tables.removals', 'feed_removals'));
    }

    /**
     * @return array{string, int|string}
     */
    private static function key(Model|string $object, int|string|null $objectId): array
    {
        if ($object instanceof Model) {
            return [$object->getMorphClass(), $object->getKey()];
        }

        if ($objectId === null) {
            throw new InvalidArgumentException('A story key is verb + object type + object id; an activity without an object has no key and no removal evidence.');
        }

        return [$object, $objectId];
    }

    private static function activity(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }
}
