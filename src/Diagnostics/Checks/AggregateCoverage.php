<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Diagnostics\Reachability;
use Storyfeed\StoryfeedManager;

/**
 * A group on an aggregate axis without aggregate grammar renders the singular
 * headline of its head member — "Sally uploaded a file" over a node that
 * carries three actors. Silent, and wrong.
 *
 * TWO SENTENCES, NOT ONE (2026-08-27). This check kept being right about the
 * database and wrong about what the reader would do next: it warned for every
 * clustered (axis, verb) pair regardless of whether any surface would ever
 * render that axis. A consumer got eight warnings on a `->live()` dashboard;
 * five were `object.*` and one `targets.*`, and `FeedBuilder::winning()` shows
 * live reads only `repeat` and authored composites — six templates that could
 * not have fired, asked for by name. So the registry now answers the second
 * half: `Reachability` reads each REGISTERED feed's declared mode, and a pair
 * nothing can read is reported as LATENT rather than as a gap to go fix.
 *
 * WHAT LATENT IS NOT. It is not an all-clear and it is not silence — the pair
 * is still reported, with its own code, because a cluster forming with no
 * template is worth knowing about and becomes visible the instant a surface
 * changes mode. It carries no Fix, because `--stubs` printing six
 * unrenderable registrations is the exact harm this fixes. And it is only
 * ever said when the registry can actually say it: no feeds registered, or
 * one feed that would not inspect, and every pair reverts to the plain
 * warning plus a note that reachability is unknown. Silence that reads as
 * coverage is the failure mode this check has already committed twice.
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

        $missing = [];

        foreach ($pairs as $pair) {
            if ($storyfeed->aggregateTemplate($pair->axis, $pair->verb) !== null) {
                continue;
            }

            $missing[] = [(string) $pair->axis, (string) $pair->verb];
        }

        if ($missing === []) {
            return;
        }

        $reach = Reachability::of($storyfeed);

        // The caveats come FIRST, so nothing below them can be read as a
        // complete answer by someone who stopped at the first warning.
        yield from $this->caveats($reach);

        foreach ($missing as [$axis, $verb]) {
            $readers = $reach->readers($axis, $verb);

            if ($readers === [] && $reach->isConclusive()) {
                yield $this->latent($axis, $verb, $reach);

                continue;
            }

            yield Finding::warning(
                'aggregates.missing',
                "No aggregate grammar resolves for `{$axis}.{$verb}` — those group nodes fall back "
                .'to the singular headline only when its tokens are safe for the axis, and otherwise render '
                .'with NO headline at all. Register one with Storyfeed::aggregateGrammar().',
                [
                    'axis' => $axis,
                    'verb' => $verb,
                    'read_by' => $readers === [] ? null : implode(', ', $readers),
                ],
                // Tokens derived from the axis recipe, so the suggested
                // snippet cannot propose one the axis fails to pin.
                Fix::make(
                    'aggregateGrammar',
                    "{$axis}.{$verb}",
                    $storyfeed->aggregateTokens($axis) ?? [],
                ),
            );
        }
    }

    /**
     * A pair that clusters, has no grammar, and that no registered feed's
     * declared mode can put on screen. Reportage, not a gap — and deliberately
     * without a Fix, since authoring it today changes nothing on any surface.
     */
    protected function latent(string $axis, string $verb, Reachability $reach): Finding
    {
        return Finding::info(
            'aggregates.latent',
            "`{$axis}.{$verb}` clusters and has no aggregate grammar, but no registered feed reads the "
            ."`{$axis}` axis — the registry declares {$reach->modes()}, and only summary() renders group "
            .'nodes outside `repeat` and `composite`. Nothing renders wrong today, so no stub is offered; '
            .'this becomes a real gap the moment a surface reads it, and a call site can override a '
            .'declared mode without touching the feed.',
            ['axis' => $axis, 'verb' => $verb, 'modes' => $reach->modes()],
        );
    }

    /**
     * What doctor does NOT know, said out loud. Both branches leave every pair
     * on the plain warning: a missing answer must never downgrade a real one.
     *
     * @return iterable<int, Finding>
     */
    protected function caveats(Reachability $reach): iterable
    {
        if (! $reach->hasFeeds()) {
            yield Finding::info(
                'aggregates.reachability_unknown',
                'No feeds are registered, so read-mode reachability is unknown: every clustered pair below is '
                .'reported, including any that no surface in this app could ever render. Registering your feeds '
                .'with Storyfeed::feeds([...]) lets this check tell a real gap from a latent one.',
                ['feeds' => 0],
            );

            return;
        }

        foreach ($reach->opaque() as $feed => $exception) {
            yield Finding::info(
                'aggregates.reachability_unknown',
                "Feed `{$feed}` threw {$exception} while being inspected, so its read mode is unknown and no pair "
                .'below can be called unreadable — they are all reported as gaps. Declared in '
                .$reach->source($feed).'.',
                ['feed' => $feed, 'exception' => $exception],
            );
        }
    }
}
