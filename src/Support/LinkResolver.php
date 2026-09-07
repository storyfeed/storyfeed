<?php

namespace Storyfeed\Support;

use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Throwable;

/**
 * Regenerate an entity's live media from its snapshot data — shared by the
 * payload presenter and the AS2.0 serializer so the two surfaces cannot
 * drift. One broken resolver never breaks a feed: failures are reported
 * and degrade to null.
 *
 * The seam survives the fold. Until 2026-09-05 this was where two resolver
 * contracts met — HasFeedMedia::feedMedia() winning over the older
 * Feedable::toFeedLink() — and it stays the single call site so that when
 * the contract grows both surfaces pick it up at once. It did, with issue
 * #4: FeedContext::model() arrived as a constructor argument that
 * each caller passes and this class never reads. Neither caller has ever
 * known which method answered, and neither needs to now that only one can.
 *
 * REPORTED ONCE PER CLASS, NOT ONCE PER ENTITY (2026-09-06, issue #9). A
 * resolver that throws usually throws for its whole class — it reached for
 * a panel, or the authenticated user, and there is no request. Reporting
 * that per entity means a queued digest rendering a hundred rows about one
 * broken class writes a hundred identical reports, and if the listener then
 * fails, a hundred copies land in `failed_jobs` with it. The first failure
 * of a class is reported; every later entity of that class degrades in
 * silence. A SECOND class still reports, because the second class is news.
 *
 * WHICH IS WHY THIS IS AN INSTANCE. `resolve()` was static, and a static
 * memo would have outlived the page that filled it: a worker's second
 * digest, about the same broken class, would report nothing at all, and
 * "never" is a different bug from "once". ModelHydrator's docblock states
 * the same rule for the same reason — a map that outlives a page serves one
 * page's models to the next — and this class now takes its scope from the
 * same place: NodePresenter::forPage() holds one for the page it is
 * presenting, the AS2 serializer takes one per document, and
 * CollectionSerializer passes one across the page of documents it builds.
 * A resolver built without a scope gets one of its own, which reports every
 * time: correct, only not deduped.
 *
 * NOT A CACHE. Only the fact that a class was reported is kept; the media
 * itself is resolved fresh for every entity, because two entities of a
 * class have two different links.
 */
class LinkResolver
{
    /** @var array<class-string, true> classes whose resolver has thrown and been reported in this scope */
    private array $reported = [];

    public function resolve(FeedContext $context): ?FeedMedia
    {
        $class = MorphResolver::classFor($context->type());

        if ($class === null || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        try {
            return $class::feedMedia($context);
        } catch (Throwable $e) {
            if (! isset($this->reported[$class])) {
                $this->reported[$class] = true;

                report($e);
            }
        }

        return null;
    }
}
