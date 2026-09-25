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

        // A tombstoned row asks under its former type, as the read path does.
        $query = $this->activities()->whereNull('actor_type')->whereNull('actor_id');
        $objectType = $this->objectTypeOf($query);

        $pairs = $query->toBase()->selectRaw("{$objectType} as object_type, verb")->distinct()->get();

        foreach ($pairs as $pair) {
            $type = $pair->object_type === null ? null : (string) $pair->object_type;
            $verb = (string) $pair->verb;

            if ($storyfeed->actorlessTemplate($type, $verb) !== null) {
                continue;
            }

            $key = ($type ?? '*').".{$verb}";

            yield new Finding(
                'actorless.missing',
                Severity::Info,
                "`{$key}` occurs with a null actor but has no actorless template. "
                .'Add ->anonymousHeadline() to its definition (or Storyfeed::actorlessGrammar()) to author a sentence without an actor slot, '
                .'or give the actor an optional segment: \'[:actor ]confirmed :object\'. '
                .'These rows currently use the ordinary grammar and renderer fallback.',
                ['verb' => $verb, 'type' => $type],
                Fix::make('actorlessGrammar', $key),
            );
        }
    }
}
