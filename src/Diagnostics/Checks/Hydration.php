<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\FeedContext;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ModelHydrator;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\SurfaceScanner;
use Storyfeed\Testing\StoryfeedFake;
use Throwable;

/**
 * Every Feedable whose resolver hydrates its model — the bill for
 * `$context->model()`, made readable (issue #5).
 *
 * The accessor is lazy and batched, so a page pays one query per hydrating
 * class however many entities ask. That is cheap, and it is also invisible:
 * nothing in a payload, a test or a page says "this feed loads models". It
 * shows up as a slow surface months later, blamed on whatever shipped most
 * recently. This check names the classes that pay, so a surface's behaviour
 * is a fact someone can read rather than infer.
 *
 * INFO, NOT WARNING. Hydrating is a legitimate choice — the docblock on
 * model() says when to make it — and a report that warns about a decision
 * the author made on purpose is the report people stop reading. Nothing
 * here is a finding; the check is silent on an app where no resolver asks.
 *
 * HOW IT KNOWS, and why the other two ways lost. Three ways to learn that a
 * resolver hydrates were on the table (issue #5):
 *
 *   static analysis   grep the method body for `->model(` — fragile the first
 *                     time a resolver delegates to a helper, and wrong the
 *                     first time one calls it on a branch the app never takes;
 *   a declared marker an interface or method saying "I hydrate" — honest, but
 *                     a marker the author forgot is a check that says nothing,
 *                     and the whole point is the author forgetting;
 *   a runtime flag    `ModelHydrator::requested()` already records who asked,
 *                     but a page's map dies with the page, so doctor would
 *                     only ever see what happened to render before it ran.
 *
 * So doctor RUNS THE RESOLVER ITSELF. Each candidate class is handed a
 * FeedContext whose identity map was constructed disabled, and asked for its
 * media once per registered feed plus once with no feed, the way an ad-hoc
 * builder and the AS2 serializer ask. A disabled map records the request
 * before it consults the switch and answers null with no query, so the probe
 * learns who asks without paying for an answer. It is the runtime flag,
 * driven by doctor rather than waited for.
 *
 * THAT IS READ-ONLY BY CONTRACT, not by hope. `Feedable::feedMedia()` is
 * documented as a pure function of its context — cheap, side-effect-free,
 * and already called for group exemplars that are never painted. The probe
 * is that same call with a map that cannot reach the database; a resolver
 * that writes on it was already writing on every page it never appeared on.
 *
 * WHAT IT CANNOT SEE, said out loud. A resolver that hydrates only under a
 * feed name nobody registered, or only for a snapshot shaped unlike the
 * latest one, is not seen — the probe uses the newest snapshot recorded for
 * the alias as its representative row, and nothing it does not carry. A
 * class with no snapshot yet is probed with an empty one, and if it throws
 * on that (a naive `$data['id']` does) the check stays silent rather than
 * naming a resolver that has never run for a problem it does not have. A
 * resolver that throws on its OWN snapshot is reported as opaque, as
 * Reachability reports a feed it cannot inspect: an unanswered question is
 * not a clean answer. And `with:` relations ride the batch, so they change
 * how wide the query is, not how many there are; nested access past them is
 * the N+1 the model() docblock warns about, and no probe can count it.
 *
 * THE NUMBER, AND WHY IT IS A BOUND. A page's cost is one query per hydrating
 * class that APPEARS on it, so the honest figure is per page, not per app.
 * When there is traffic to look at, the check reads the most recent default
 * page's worth of activities and counts the hydrating classes on it — "this
 * page pays two" is a number an operator can hold against a slow-query log.
 * Without traffic it reports the bound: every hydrating class, one query
 * each, on every page it reaches.
 */
class Hydration extends Check
{
    /** The default FeedBuilder page, so the estimate describes a page a reader would actually get. */
    protected const PAGE = 30;

    public function __construct(
        protected SurfaceScanner $scanner,
    ) {}

    public function name(): string
    {
        return 'hydration';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $classes = $this->candidates($storyfeed);

        if ($classes === []) {
            return;
        }

        $feeds = [null, ...array_keys($storyfeed->registeredFeeds())];
        $enabled = (bool) config('storyfeed.hydration.enabled', true);
        $snapshots = $this->hasTable('snapshots');

        /** @var array<string, class-string> alias => class, for the page estimate */
        $hydrating = [];

        foreach ($classes as $class) {
            $alias = (new $class)->getMorphClass();
            $snapshot = $snapshots ? $this->representative($alias) : null;

            $asked = [];
            $answered = 0;
            $threw = null;

            foreach ($feeds as $feed) {
                // Built disabled: the request is recorded, nothing is loaded.
                $hydrator = new ModelHydrator(enabled: false);

                $context = new FeedContext(
                    type: $alias,
                    id: $snapshot?->model_id,
                    label: $snapshot?->label,
                    data: $snapshot === null ? [] : ($snapshot->data ?? []),
                    feed: $feed,
                    hydrator: $hydrator,
                );

                try {
                    $class::feedMedia($context);
                    $answered++;
                } catch (Throwable $e) {
                    $threw = $e::class;

                    continue;
                }

                if ($hydrator->requested() !== []) {
                    $asked[] = $feed ?? 'an unnamed feed';
                }
            }

            if ($answered === 0) {
                // No snapshot means the read path has never called this
                // resolver either; a throw on an empty probe is the naive
                // shape, not evidence. With its own snapshot it is opaque.
                if ($snapshot !== null) {
                    yield Finding::info(
                        'hydration.opaque',
                        "[{$class}]'s feedMedia() threw {$threw} when probed with its latest snapshot, so whether it "
                        .'hydrates cannot be said. The read path reports that exception and renders the entity '
                        .'without a link; fix the resolver and this check can answer.',
                        ['model' => $class, 'alias' => $alias, 'exception' => $threw],
                    );
                }

                continue;
            }

            if ($asked === []) {
                continue;
            }

            $hydrating[$alias] = $class;

            $under = count($asked) === count($feeds)
                ? 'under every feed'
                : 'under '.implode(', ', $asked);

            yield Finding::info(
                'hydration.model',
                "[{$class}] hydrates its model in feedMedia() {$under} — one query for `{$alias}` on every page it "
                .'appears on, batched across the page.'
                .($enabled
                    ? ''
                    : ' Hydration is switched off (`storyfeed.hydration.enabled`), so today that call answers null '
                    .'with no query and the resolver takes its null branch.'),
                ['model' => $class, 'alias' => $alias, 'feeds' => implode(', ', $asked), 'enabled' => $enabled],
            );
        }

        if ($hydrating === [] || $storyfeed instanceof StoryfeedFake || ! $this->hasTable('activities')) {
            return;
        }

        yield from $this->page($hydrating);
    }

    /**
     * What the most recent default page would pay: the hydrating classes that
     * actually appear on it, one query each.
     *
     * @param  array<string, class-string>  $hydrating
     * @return iterable<Finding>
     */
    protected function page(array $hydrating): iterable
    {
        $rows = $this->activities()
            ->toBase()
            ->select(['actor_type', 'object_type', 'target_type', 'context_type'])
            ->orderByDesc('published_at')
            ->limit(self::PAGE)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $present = [];

        foreach ($rows as $row) {
            foreach (['actor_type', 'object_type', 'target_type', 'context_type'] as $column) {
                if ($row->{$column} !== null && isset($hydrating[$row->{$column}])) {
                    $present[$row->{$column}] = true;
                }
            }
        }

        $queries = count($present);
        $aliases = implode(', ', array_map(fn (string $alias) => "`{$alias}`", array_keys($present)));
        $page = self::PAGE;

        yield Finding::info(
            'hydration.page',
            $queries === 0
                ? "A page of the {$page} most recent activities carries none of the classes that hydrate, so it "
                    .'pays no hydration queries; pages that reach them pay one per class.'
                : "A page of the {$page} most recent activities carries {$queries} hydrating "
                    .str('class')->plural($queries)." ({$aliases}), so it pays {$queries} hydration "
                    .str('query')->plural($queries).' on top of its own — the same count at twenty nodes or two hundred.',
            ['page' => $page, 'queries' => $queries, 'aliases' => implode(', ', array_keys($present))],
        );
    }

    /**
     * The Feedable classes worth asking: everything the surface scan declares,
     * plus whatever fills a role in the recorded activities — a model outside
     * the scanned paths still pays if it is on the page.
     *
     * @return list<class-string<Model&Feedable>>
     */
    protected function candidates(StoryfeedManager $storyfeed): array
    {
        $classes = [];

        foreach ($this->scanner->scan()['feedable'] as $class) {
            // The scan admits any instantiable Feedable; only a model has a
            // morph alias to probe under, or a row to be hydrated.
            if (is_a($class, Model::class, true)) {
                $classes[] = $class;
            }
        }

        $recorded = match (true) {
            $storyfeed instanceof StoryfeedFake => $storyfeed->recordedAliases(),
            $this->hasTable('activities') => $this->recordedAliases(),
            default => [],
        };

        foreach ($recorded as $alias) {
            $class = MorphResolver::classFor($alias);

            if ($class !== null && is_a($class, Model::class, true) && is_a($class, Feedable::class, true)) {
                $classes[] = $class;
            }
        }

        /** @var list<class-string<Model&Feedable>> */
        return array_values(array_unique($classes));
    }

    /**
     * The newest snapshot for an alias — the row the resolver would most
     * recently have been handed for real.
     */
    protected function representative(string $alias): ?Snapshot
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);

        return $model::query()->where('model_type', $alias)->latest('updated_at')->first();
    }
}
