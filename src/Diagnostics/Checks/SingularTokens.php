<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * A singular template that names a role its activities never carry renders
 * the absent placeholder as content: "restored a clause somewhere", in
 * italics, forever, because no `document.clause_restored` activity has ever
 * had a target. The sentence is grammatical and the placeholder is honest —
 * it says the role is absent — but a reader cannot tell "the location is
 * unknown" from "this sentence should never have mentioned a location". The
 * authoring bug renders as content, which is the same failure the aggregate
 * checks exist for: right about the data, wrong about what the reader
 * concludes.
 *
 * WHY THIS ISN'T COVERED ALREADY. `Coverage` asserts a template EXISTS for a
 * recorded (object_type, verb) pair; `AggregateTokens` and
 * `AggregateCoverage` only ever look at aggregate templates. Nothing
 * validated a singular template's tokens against the roles its activities
 * actually carry — registration accepts anything.
 *
 * WHY IT IS THE DOCTOR'S JOB NOW (2026-08-27). It was informally checked by
 * a human reading the feed, until groups started closed by default: rows
 * that were visibly wrong yesterday are behind a click today. Closing groups
 * removed an app's ability to see its own broken sentences, so the check has
 * to be deliberate. That is the debt side of a change we shipped.
 *
 * DATA-DRIVEN, per `Coverage`'s reasoning: which roles an app's activities
 * carry has been guessed wrong in practice, and one run of real traffic
 * settles it.
 *
 * EVALUATED PER TEMPLATE KEY, NOT PER PAIR. A `*.*` catch-all naming
 * `:target` is not wrong because ONE pair lacks a target — it is wrong when
 * NOTHING it renders has one. Grouping by the key that actually resolves
 * asks the right question and yields one finding per edit to make.
 *
 * ACTOR IS AN INFO, AND THE ASYMMETRY IS THE REASON. A null actor has a
 * documented meaning — anonymous, never conflated with a system actor — so
 * ":actor" over all-anonymous rows still reads correctly and is at most
 * worth knowing. `object`, `target` and `context` have no such meaning;
 * their placeholders exist only so a template naming a missing role "still
 * reads", which is precisely the behaviour that hid this.
 *
 * SCOPE. "Never" only. A role carried by SOME of a template's activities is
 * the placeholder doing its actual job, and warning on it would bury this.
 * Whether the non-actor placeholders should exist at all — or whether an
 * unfillable clause should drop — is a payload/renderer design question and
 * is deliberately not answered here.
 */
class SingularTokens extends Check
{
    /** The roles a singular template can name, and the column that carries each. */
    protected const ROLES = [
        'actor' => 'actor_type',
        'object' => 'object_type',
        'target' => 'target_type',
        'context' => 'context_type',
    ];

    /** How many pairs a message names before it starts counting instead. */
    protected const PAIRS_SHOWN = 4;

    public function name(): string
    {
        return 'roles';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        /** @var array<string, array{template: string, pairs: list<string>, total: int, roles: array<string, int>}> $keys */
        $keys = [];

        foreach ($this->carriage() as $row) {
            $template = $storyfeed->template($row->type, $row->verb);

            // Closures pre-render; there are no tokens to inspect.
            if (! is_string($template)) {
                continue;
            }

            $key = $storyfeed->templateKey($row->type, $row->verb) ?? ($row->type ?? '*').'.'.$row->verb;

            $keys[$key] ??= ['template' => $template, 'pairs' => [], 'total' => 0, 'roles' => []];
            $keys[$key]['pairs'][] = ($row->type ?? '(no object)').'.'.$row->verb;
            $keys[$key]['total'] += (int) $row->total;

            foreach (array_keys(self::ROLES) as $role) {
                $keys[$key]['roles'][$role] = ($keys[$key]['roles'][$role] ?? 0) + (int) $row->{$role};
            }
        }

        foreach ($keys as $key => $entry) {
            preg_match_all('/:[a-z]+/', $entry['template'], $matches);
            $named = array_unique($matches[0]);

            foreach (self::ROLES as $role => $column) {
                if (! in_array(":{$role}", $named, true) || $entry['roles'][$role] > 0) {
                    continue;
                }

                yield $this->finding($key, $role, $entry);
            }
        }
    }

    /**
     * Recorded (object_type, verb) pairs with a per-role count of the
     * activities that actually carry each role. One grouped query; count(col)
     * counts non-nulls on every driver we support.
     */
    protected function carriage(): iterable
    {
        $counts = array_map(
            fn (string $column, string $role) => "count({$column}) as {$role}",
            self::ROLES,
            array_keys(self::ROLES),
        );

        // toBase(): these rows are aggregate tuples, not Activity models.
        return $this->activities()
            ->toBase()
            ->selectRaw(implode(', ', ['object_type as type', 'verb', 'count(*) as total', ...$counts]))
            ->groupBy('object_type', 'verb')
            ->get();
    }

    /**
     * @param  array{template: string, pairs: list<string>, total: int, roles: array<string, int>}  $entry
     */
    protected function finding(string $key, string $role, array $entry): Finding
    {
        $subject = [
            'key' => $key,
            'token' => ":{$role}",
            'role' => $role,
            'activities' => $entry['total'],
            'pairs' => implode(' ', $entry['pairs']),
        ];

        $where = 'Recorded: '.$this->pairList($entry['pairs']).'.';

        if ($role === 'actor') {
            return Finding::info(
                'roles.always_anonymous',
                "Note: grammar `{$key}` names `:actor`, and none of the {$entry['total']} activities it renders "
                .'carry one — every one of them is anonymous, which is a documented state rather than a bug, so '
                ."the sentence still reads. {$where}",
                $subject,
            );
        }

        return Finding::warning(
            'roles.never_carried',
            "Grammar `{$key}` names `:{$role}`, but none of the {$entry['total']} activities it renders carry a "
            ."{$role} — every one of those headlines renders the absent placeholder as content, and a reader "
            ."cannot tell \"the {$role} is unknown\" from \"this sentence should never have named one\". Drop the "
            ."clause, or start recording the {$role}. {$where}",
            $subject,
        );
    }

    /** @param list<string> $pairs */
    protected function pairList(array $pairs): string
    {
        sort($pairs);

        $shown = array_slice($pairs, 0, self::PAIRS_SHOWN);
        $rest = count($pairs) - count($shown);

        return implode(', ', array_map(fn (string $pair) => "`{$pair}`", $shown))
            .($rest > 0 ? " and {$rest} more" : '');
    }
}
