<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * A grouping hash at the column limit has probably been truncated — and
 * truncated hashes OVER-group: unrelated activities collapse into one node.
 * (Learned the hard way: a legacy app stored hashes in VARCHAR(50) with no
 * guard.)
 */
class HashLengths extends Check
{
    public function name(): string
    {
        return 'hashes';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('groupings')) {
            return;
        }

        $suspect = $this->groupings()
            ->whereRaw($this->lengthExpression('hash').' >= 255')
            ->count();

        if ($suspect > 0) {
            yield Finding::warning(
                'hashes.truncated',
                "{$suspect} grouping hash(es) are at the 255-character column limit — likely truncated, which "
                .'silently over-groups unrelated activities. Shorten the strategy output (e.g. digest long parts).',
                ['count' => $suspect],
            );
        }
    }
}
