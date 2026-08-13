<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SurfaceScanner;

/**
 * Feed surface that publishes nothing — THE detector for "the feed stopped
 * keeping up with the app".
 *
 * The reported failure this exists for: the grammar gets authored once, new
 * modules ship, and nothing tells you the feed has fallen behind. `freshness`
 * catches the crude version (nothing arriving at all). This catches the specific
 * version: something in the app has DECLARED itself part of the feed and never
 * appears in it.
 *
 * Only `Feedable` models and `PublishesToFeed` implementors count, because both
 * are explicit statements of intent — implementing Feedable says "this thing
 * belongs in the feed", so one with no grammar and no activity is a
 * contradiction rather than a guess. Plain app events are deliberately excluded:
 * every app has hundreds, and a heuristic list of "events that maybe should
 * publish" is noise that trains people to ignore the report.
 *
 * The honest limit, and it is not small: a module that never touches Storyfeed
 * at all is invisible to Storyfeed, and no check inside the package can see it.
 */
class UnwiredSurface extends Check
{
    public function __construct(
        protected SurfaceScanner $scanner,
    ) {}

    public function name(): string
    {
        return 'surface';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        $surface = $this->scanner->scan();

        $recordedTypes = $this->recordedAliases();

        foreach ($surface['feedable'] as $model) {
            $alias = (new $model)->getMorphClass();

            if (in_array($alias, $recordedTypes, true)) {
                continue;
            }

            if ($storyfeed->templateKey($alias, '*') !== null) {
                // Authored but not yet recorded — that is `dead`, not unwired,
                // and authoring ahead of traffic is a workflow we recommend.
                continue;
            }

            yield Finding::warning(
                'surface.unwired',
                "[{$model}] implements Feedable — declaring that it belongs in the feed — but no activity has "
                ."ever been recorded about `{$alias}` and no grammar is authored for it. Either it should be "
                .'publishing and nothing does, or the contract is left over from something removed.',
                ['model' => $model, 'alias' => $alias],
            );
        }

        foreach ($surface['publishers'] as $publisher) {
            yield Finding::info(
                'surface.publisher',
                "[{$publisher}] publishes to the feed.",
                ['publisher' => $publisher],
            );
        }
    }

    /**
     * Aliases that appear in ANY role, not just as the object.
     *
     * A model used only as an actor or a target — a User, a Customer — is
     * obviously wired into the feed. Checking `object_type` alone would report
     * every one of them as unwired, which is the noise that gets a report
     * ignored, and it is a nastier mistake than missing a real gap.
     *
     * @return array<int, string>
     */
    protected function recordedAliases(): array
    {
        $aliases = [];

        foreach (['actor', 'object', 'target', 'context'] as $role) {
            $aliases = [
                ...$aliases,
                ...$this->activities()->distinct()->toBase()->pluck("{$role}_type")->filter()->all(),
            ];
        }

        return array_values(array_unique($aliases));
    }
}
