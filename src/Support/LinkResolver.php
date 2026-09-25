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
 *
 * A DEFERRED BODY IS THE SAME KIND OF FAILURE, LATER (2026-09-25, todo
 * 1472). A body closure runs when the body is read, not when feedMedia()
 * returns, so the try above never saw it: one throwing closure failed the
 * whole feed read. `body()` builds it under the same rule — reported once
 * per class per scope, left out, the entity kept with its label, url and
 * media. Its own ledger, because a class whose resolver throws never gets
 * as far as a body, and a class whose body throws has news of its own.
 *
 * THE ALIAS IS REMEMBERED, THE SAME WAY (2026-09-23, todo 1338). What an
 * alias names is the one answer that IS the same for every entity on the
 * page: a summary page asks about ~200 entities of ~4 aliases, and each ask
 * cost three config() reads inside MorphResolver::classFor(). So each alias
 * is resolved once per scope, as Laravel's CompiledRouteCollection keeps
 * its `nameCache`. Scoped to the instance for the reason above: a page
 * never sees the morph map or config of the page before it.
 */
class LinkResolver
{
    /** @var array<class-string, true> classes whose resolver has thrown and been reported in this scope */
    private array $reported = [];

    /** @var array<string, true> classes (or the alias, when it named none) whose deferred body has thrown and been reported in this scope */
    private array $bodyReported = [];

    /** @var array<string, class-string|null> what each alias resolved to in this scope, null included */
    private array $classes = [];

    public function resolve(FeedContext $context): ?FeedMedia
    {
        $alias = $context->type();

        $class = array_key_exists($alias, $this->classes)
            ? $this->classes[$alias]
            : $this->classes[$alias] = MorphResolver::classFor($alias);

        if ($class === null || ! app(Feedables::class)->isFeedable($class)) {
            return null;
        }

        try {
            return app(Feedables::class)->feedMedia($class, $context);
        } catch (Throwable $e) {
            if (! isset($this->reported[$class])) {
                $this->reported[$class] = true;

                report($e);
            }
        }

        return null;
    }

    /**
     * The media's body, built now, with a body that throws reported and
     * left out.
     *
     * @return list<array<string, mixed>>
     */
    public function body(?FeedMedia $media, string $alias): array
    {
        return $media?->resolveBody(function (Throwable $e) use ($alias): void {
            $class = $this->classes[$alias] ?? $alias;

            if (! isset($this->bodyReported[$class])) {
                $this->bodyReported[$class] = true;

                report($e);
            }
        }) ?? [];
    }
}
