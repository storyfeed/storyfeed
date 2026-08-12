<?php

namespace Storyfeed\Contracts;

/**
 * The escape hatch for the one staleness no structural fingerprint can
 * see: identical keys, changed MEANING ("label format changed", "status
 * now holds a different vocabulary"). Bump the version and every stored
 * snapshot of this model reads as shape-stale; the trickle re-snapshots
 * them on its next runs.
 *
 * Structural changes (added/removed/renamed keys, changed component —
 * including changes inside DTOs feeding FeedEntity::data) are detected
 * AUTOMATICALLY from the output shape; declare a version only when
 * semantics move without the shape moving.
 */
interface HasFeedShapeVersion
{
    public static function feedShapeVersion(): int;
}
