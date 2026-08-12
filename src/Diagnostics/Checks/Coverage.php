<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\StoryfeedManager;

/**
 * Singular grammar and icon coverage over the (object_type, verb) pairs the
 * app has ACTUALLY recorded. Data-driven on purpose: reasoning about which
 * pairs an app can produce has been wrong in practice, and one run of real
 * traffic settles it.
 */
class Coverage extends Check
{
    public function name(): string
    {
        return 'grammar';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        // toBase(): these rows are aliased column tuples, not Activity models.
        $pairs = $this->activities()->distinct()->toBase()->get(['object_type as type', 'verb']);

        foreach ($pairs as $pair) {
            $label = ($pair->type ?? '(no object)').'.'.$pair->verb;
            $key = ($pair->type ?? '*').'.'.$pair->verb;
            $subject = ['type' => $pair->type, 'verb' => $pair->verb];

            if ($storyfeed->template($pair->type, $pair->verb) === null) {
                yield Finding::warning(
                    'grammar.missing',
                    "No grammar entry resolves for `{$label}` — headlines will be null.",
                    $subject,
                    Fix::make('grammar', $key, [':actor', ':object', ':target', ':context']),
                );
            }

            if ($storyfeed->icon($pair->type, $pair->verb) === null) {
                yield Finding::warning(
                    'grammar.icon_missing',
                    "No icon resolves for `{$label}`.",
                    $subject,
                    Fix::make('icons', $key),
                );
            }

            $type = $storyfeed->activityType($pair->verb);

            if ($type === null) {
                yield Finding::info(
                    'grammar.unmapped_verb',
                    "Note: verb `{$pair->verb}` has no AS2.0 mapping — will serialize as base `Activity`.",
                    $subject,
                );
            }

            if ($type instanceof ActivityType && $type->isIntransitive() && $pair->type !== null) {
                $count = $this->activities()
                    ->where('verb', $pair->verb)
                    ->where('object_type', $pair->type)
                    ->count();

                yield Finding::warning(
                    'grammar.intransitive_with_object',
                    "Verb `{$pair->verb}` maps to intransitive type {$type->value} but {$count} activities carry "
                    .'an object — these serialize as base `Activity`. Map the verb to a transitive type, or stop '
                    .'setting an object.',
                    $subject + ['as2_type' => $type->value, 'activities' => $count],
                );
            }
        }
    }
}
