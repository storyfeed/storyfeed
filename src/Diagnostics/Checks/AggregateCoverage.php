<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\StoryfeedManager;

/**
 * A group on an aggregate axis without aggregate grammar renders the singular
 * headline of its head member — "Sally uploaded a file" over a node that
 * carries three actors. Silent, and wrong.
 */
class AggregateCoverage extends Check
{
    public function name(): string
    {
        return 'aggregates';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('groupings') || ! $this->hasTable('activities')) {
            return;
        }

        $groupings = $this->table('groupings');
        $activities = $this->table('activities');

        // ALL registered axes, fallback included: the fallback's exclusion
        // from aggregateAxes() is about curation priority — a different
        // question from whether a headline resolves. Repeat groups render
        // aggregate headlines like any other axis, and a missing repeat.*
        // key used to be structurally invisible here (found live: `archive`
        // slipped four rounds of audits). Only clusters of 2+ count —
        // winners in a cluster of one render as plain activity nodes.
        $clustered = $this->groupings()
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->where("{$groupings}.winner", true)
            ->whereIn("{$groupings}.bucket", array_keys($storyfeed->registeredAxes()))
            ->groupBy(["{$groupings}.bucket", "{$groupings}.hash"])
            ->havingRaw('count(*) > 1');

        $pairs = $this->activities()
            ->join($groupings, "{$groupings}.activity_id", '=', "{$activities}.id")
            ->where("{$groupings}.winner", true)
            ->joinSub($clustered, 'clustered', function ($join) use ($groupings) {
                $join->on('clustered.bucket', '=', "{$groupings}.bucket")
                    ->on('clustered.hash', '=', "{$groupings}.hash");
            })
            ->distinct()
            // toBase(): aliased tuples, not Activity models.
            ->toBase()
            ->get(["{$groupings}.bucket as axis", "{$activities}.verb"]);

        foreach ($pairs as $pair) {
            if ($storyfeed->aggregateTemplate($pair->axis, $pair->verb) !== null) {
                continue;
            }

            yield Finding::warning(
                'aggregates.missing',
                "No aggregate grammar resolves for `{$pair->axis}.{$pair->verb}` — those group nodes fall back "
                .'to the singular headline only when its tokens are safe for the axis, and otherwise render '
                .'with NO headline at all. Register one with Storyfeed::aggregateGrammar().',
                ['axis' => $pair->axis, 'verb' => $pair->verb],
                // Tokens derived from the axis recipe, so the suggested
                // snippet cannot propose one the axis fails to pin.
                Fix::make(
                    'aggregateGrammar',
                    "{$pair->axis}.{$pair->verb}",
                    $storyfeed->aggregateTokens((string) $pair->axis) ?? [],
                ),
            );
        }
    }
}
