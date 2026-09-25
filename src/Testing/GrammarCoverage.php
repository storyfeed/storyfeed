<?php

namespace Storyfeed\Testing;

/**
 * @deprecated Use HeadlineCoverage; removed before v1.
 */
class GrammarCoverage extends HeadlineCoverage
{
    /**
     * @deprecated Use HeadlineCoverage::assertCoversGroups(); removed before v1.
     */
    public static function assertCoversAggregates(bool $allowWildcard = false): void
    {
        static::assertCoversGroups($allowWildcard);
    }

    /**
     * @deprecated Use HeadlineCoverage::assertCoversPossibleGroups(); removed before v1.
     */
    public static function assertCoversPossibleAggregates(bool $allowWildcard = false): void
    {
        static::assertCoversPossibleGroups($allowWildcard);
    }
}
