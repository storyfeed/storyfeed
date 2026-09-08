<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\StoryfeedManager;

class ActorlessCoverage extends Check
{
    public function name(): string
    {
        return 'actorless';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        $verbs = $this->activities()->whereNull('actor_type')->whereNull('actor_id')
            ->distinct()->toBase()->pluck('verb');

        foreach ($verbs as $verb) {
            if ($storyfeed->actorlessTemplate($verb) !== null) {
                continue;
            }

            yield new Finding(
                'actorless.missing',
                Severity::Info,
                "Verb `{$verb}` occurs with a null actor but has no actorless template. "
                .'Register Storyfeed::actorlessGrammar() to author a sentence without an actor slot; '
                .'these rows currently use the ordinary grammar and renderer fallback.',
                ['verb' => $verb],
                Fix::make('actorlessGrammar', $verb),
            );
        }
    }
}
