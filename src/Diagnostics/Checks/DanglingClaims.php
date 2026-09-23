<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Actions\ReleaseComposite;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Composite members still claimed by a parent that no longer exists
 * (a trashed parent still exists) — counted, so an operator can see the repair did its job
 * and that nothing has started leaving them again (todo 1348).
 *
 * WHERE THEY COME FROM. A composite parent that is hard-deleted has to hand
 * its members back to inference first, because a bulk delete fires no model
 * events and the claim rows are the only index from parent to members. Until
 * 2026-09-23 `forceDeleteFromFeed()` did not, and until aa5b6b2 neither did
 * prune; both forgot the parent's own claim row and left the members'.
 *
 * INFO, NOT A WARNING, because nothing is hidden and nothing renders wrong
 * in itself. The composite node is built from its members' claim rows, so it
 * reads exactly as it did while the parent existed. What the count means is
 * that a story outlived the erasure that should have ended it: a
 * force-deleted parent hands its members back to inference, and these were
 * never handed back. The remedy is `storyfeed:curate --release`, which
 * releases them and re-decides their clusters, and moves `sync_token`
 * because the node it replaces is settled history.
 *
 * Soft-deleted parents are not counted. A trashed parent still owns its
 * members, and a restore puts the story back intact.
 *
 * Members whose own activity is gone are left to `dangling`, which already
 * counts grouping rows with no activity. Silent on a healthy install.
 */
class DanglingClaims extends Check
{
    public function name(): string
    {
        return 'claims';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities') || ! $this->hasTable('groupings')) {
            return; // Tables already reported it.
        }

        $groupings = $this->groupings()->getModel()->getTable();
        $activities = $this->activities()->getModel()->getTable();

        $claimed = ReleaseComposite::danglingClaims()
            ->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from($activities)
                ->whereColumn("{$activities}.id", "{$groupings}.activity_id"))
            ->count();

        if ($claimed === 0) {
            return;
        }

        yield Finding::info(
            'claims.parent_gone',
            "{$claimed} ".str('member')->plural($claimed).' '.($claimed === 1 ? 'is' : 'are')
            .' still claimed by a composite parent that no longer exists. The story still renders from its members, '
            .'but a force-deleted parent is meant to hand them back to inference, and these were erased before '
            .'`forceDeleteFromFeed()` and prune did. Run `php artisan storyfeed:curate --release` to release them; '
            .'it moves sync_token when it changes anything, and a second run is a no-op.',
            ['claimed' => $claimed],
        );
    }
}
