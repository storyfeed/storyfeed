<?php

use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Serialize one activity as the AS2.0 layer would.
 *
 * Lives here rather than in the test file that first needed it: a helper
 * defined in SerializationTest.php and called from ContextDocumentTest.php
 * works only when both files load into the same process, so
 * `vendor/bin/pest --parallel` failed and neither file could be run alone —
 * a confusing minute every time someone iterates on the AS2 layer.
 */
function serialize_one(Activity $activity): array
{
    return app(ActivitySerializer::class)->activity(
        $activity->fresh(ActivityRoles::cachedRelations()),
    );
}

/**
 * Register definitions made with Verb::make(), as a routes/feed.php line
 * registers its own, for tests about what definitions compile to rather than
 * how a line is written. Each keeps the source Verb::make() gave it.
 */
function defineStories(Verb ...$definitions): void
{
    foreach ($definitions as $definition) {
        app(StoryfeedManager::class)->addStory($definition);
    }
}
