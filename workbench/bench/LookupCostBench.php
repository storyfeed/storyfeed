<?php

/*
 * What do Storyfeed's definition lookups cost, next to Laravel's routing
 * tricks? (R&D todo 1336, branch rnd/lookup-perf — MEASUREMENT ONLY)
 *
 *   vendor/bin/pest workbench/bench/LookupCostBench.php
 *   php -d opcache.enable_cli=1 vendor/bin/pest workbench/bench/LookupCostBench.php
 *
 * Four questions, each answered with counts first and milliseconds second:
 *
 *   1. BOOT   — what a request pays to have the definitions at all: the
 *               uncached compile (Story facade + CompileStories) against the
 *               cached manifest (require + apply), at app scale.
 *   2. READ   — a 50-node summary page: SQL against PHP, and how many
 *               registry lookups, entity resolutions and objects the PHP
 *               side makes. Every lookup primitive timed in isolation.
 *   3. PUBLISH — one publish, and what a Request-injected Story action
 *               (scratchpad 306 ruling 3) adds to it.
 *   4. OPCACHE — whether the manifest is served from opcache (run twice).
 *
 * Output goes to STDERR so PHPUnit's output strictness leaves it alone.
 */

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\StoryDefinition;
use Storyfeed\StoryfeedManager;
use Storyfeed\StoryManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\ModelHydrator;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\StoryManifest;
use Storyfeed\Support\TombstoneRules;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

uses(TestCase::class);

const LK_VERBS = ['create', 'update', 'delete', 'restore', 'ship', 'confirm'];

/** Counts every registry read the presenter makes. */
class LkCountingManager extends StoryfeedManager
{
    /** @var array<string, int> */
    public static array $calls = [];

    public function template(?string $type, string $verb): string|Closure|null
    {
        self::$calls['template'] = (self::$calls['template'] ?? 0) + 1;

        return parent::template($type, $verb);
    }

    public function actorlessTemplate(?string $type, string $verb): string|Closure|null
    {
        self::$calls['actorlessTemplate'] = (self::$calls['actorlessTemplate'] ?? 0) + 1;

        return parent::actorlessTemplate($type, $verb);
    }

    public function aggregateTemplate(?string $axis, string $verb, ?string $objectType = null): string|Closure|null
    {
        self::$calls['aggregateTemplate'] = (self::$calls['aggregateTemplate'] ?? 0) + 1;

        return parent::aggregateTemplate($axis, $verb, $objectType);
    }

    public function icon(?string $type, string $verb): ?string
    {
        self::$calls['icon'] = (self::$calls['icon'] ?? 0) + 1;

        return parent::icon($type, $verb);
    }

    public function glyphIntent(?string $type, string $verb): ?string
    {
        self::$calls['glyphIntent'] = (self::$calls['glyphIntent'] ?? 0) + 1;

        return parent::glyphIntent($type, $verb);
    }

    public function activityType(string $verb): ActivityType|string|null
    {
        self::$calls['activityType'] = (self::$calls['activityType'] ?? 0) + 1;

        return parent::activityType($verb);
    }

    public function noun(?string $type, string $verb): string|FeedNoun|null
    {
        self::$calls['noun'] = (self::$calls['noun'] ?? 0) + 1;

        return parent::noun($type, $verb);
    }
}

/** A resource Story, as scratchpad 306 rules it: one method per verb, Request injectable. */
class LkDeliveryStory
{
    public function ship(StoryDefinition $verb, Request $request): StoryDefinition
    {
        return $verb->headline(':actor shipped :object[ to :target]')->icon('truck');
    }
}

function lk_out(string $line = ''): void
{
    fwrite(STDERR, $line.PHP_EOL);
}

/** Swap in a fresh (counting) manager, as a new request would get. */
function lk_fresh_manager(): StoryfeedManager
{
    $manager = new LkCountingManager;
    app()->instance(StoryfeedManager::class, $manager);
    app()->forgetInstance(TombstoneRules::class);
    app()->forgetInstance(StoryManager::class);
    Facade::clearResolvedInstances();

    return $manager;
}

/**
 * An app-scale definitions file: $types object types × 6 verbs, a fallback
 * per type, and the real workbench types the read page uses. 30 types is a
 * large app (the pilots have 3–12); 100 is a stress case.
 */
function lk_define(int $types, int $closures = 0): int
{
    $count = 0;

    for ($t = 0; $t < $types; $t++) {
        Story::for("type{$t}")->group(function () use ($t, $closures, &$count) {
            foreach (LK_VERBS as $verb) {
                // Not a ternary: SerializableClosure can't cut an arrow fn out of one.
                $headline = ":actor {$verb}d :object[ for :target]";
                if ($t < $closures) {
                    $headline = static fn (Activity $activity) => ':actor '.$activity->verb.' :object';
                }
                Story::verb($verb)->headline($headline)->icon("icon-{$verb}");
                $count++;
            }
            Story::fallback()->icon('activity');
            $count++;
        });
    }

    Story::for(['delivery', 'customer'])->group(function () use (&$count) {
        foreach (LK_VERBS as $verb) {
            Story::verb($verb)->headline(":actor {$verb}d :object[ for :target]")->icon("icon-{$verb}");
            $count++;
        }
        Story::fallback()->icon('activity');
        $count++;
    });

    Story::verb('comment')->headline(':actor commented on :object')->icon('chat')
        ->grouped(fn (GroupBuilder $group) => $group->repeat(':actor commented :count times'));
    Story::fallback()->icon('activity');

    return $count + 2;
}

/** @return array{0: float, 1: mixed} ms, result */
function lk_time(Closure $work): array
{
    $started = hrtime(true);
    $result = $work();

    return [(hrtime(true) - $started) / 1e6, $result];
}

/** Median ms of $reps runs. */
function lk_median(int $reps, Closure $work): float
{
    $samples = [];

    for ($i = 0; $i < $reps; $i++) {
        [$ms] = lk_time($work);
        $samples[] = $ms;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

/** µs per call, over $n calls. */
function lk_per_call(int $n, Closure $call): float
{
    $call(); // warm
    $started = hrtime(true);

    for ($i = 0; $i < $n; $i++) {
        $call();
    }

    return (hrtime(true) - $started) / 1e3 / $n;
}

it('1. boot: uncached compile against the cached manifest', function () {
    $opcache = (bool) ini_get('opcache.enable_cli');
    lk_out("\n==== 1. BOOT (opcache.enable_cli=".($opcache ? 'on' : 'off').', PHP '.PHP_VERSION.') ====');

    foreach ([[10, 0], [30, 0], [100, 0], [30, 5]] as [$types, $closures]) {
        // Uncached: what every request pays today without storyfeed:cache —
        // every definition built (one debug_backtrace each), then compiled.
        $backtraces = 0;
        $uncached = lk_median(15, function () use ($types, $closures, &$backtraces) {
            $manager = lk_fresh_manager();
            $backtraces = lk_define($types, $closures);
            $manager->compileStories();
        });

        // Build the manifest from the same definitions.
        $manager = lk_fresh_manager();
        $definitions = lk_define($types, $closures);
        $path = app(StoryManifest::class)->write($manager->compiledStories());
        $bytes = filesize($path);
        $manifest = require $path;
        $keys = array_sum(array_map(fn ($r) => is_array($r) ? count($r) : 0, $manifest));

        // Cached: require + apply + compileStories (the merge into registries).
        $cached = lk_median(51, function () {
            $manager = lk_fresh_manager();
            app(StoryManifest::class)->apply($manager);
            $manager->compileStories();
        });

        $requireOnly = lk_median(51, fn () => require $path);

        // The eager-unserialize cost: how much of a cached boot is closures.
        $unserialize = 0;
        array_walk_recursive($manifest, function ($value) use (&$unserialize) {
            if ($value instanceof Closure) {
                $unserialize++;
            }
        });

        lk_out(sprintf(
            '  %3d types (%3d definitions, %2d closure headlines): uncached %6.2f ms (%d backtraces) | cached %5.2f ms (require alone %5.2f ms, %d keys, %d KB, %d closures unserialised per boot)',
            $types, $definitions, $closures, $uncached, $backtraces, $cached, $requireOnly, $keys, intdiv($bytes, 1024), $unserialize,
        ));

        app(StoryManifest::class)->delete();
    }

    // Does anything in a closure-free manifest unserialize per request?
    $manager = lk_fresh_manager();
    lk_define(30);
    $source = file_get_contents(app(StoryManifest::class)->write($manager->compiledStories()));
    lk_out('  closure-free manifest: '.substr_count($source, '__set_state').' __set_state calls, '
        .substr_count($source, 'unserialize').' unserialize strings');
    app(StoryManifest::class)->delete();

    expect(true)->toBeTrue();
});

it('2. read: a 50-node summary page', function () {
    lk_out("\n==== 2. READ ====");

    $manager = lk_fresh_manager();
    lk_define(30);

    // ~600 activities over three days: 20 actors, 60 deliveries, 10 customers,
    // seven verbs, so the page mixes solos and groups.
    mt_srand(1336);
    $users = collect(range(1, 20))->map(fn ($i) => User::create(['name' => "User {$i}", 'email' => "u{$i}@lk.test"]));
    $customers = collect(range(1, 10))->map(fn ($i) => Customer::create(['name' => "Customer {$i}"]));
    $deliveries = collect(range(1, 60))->map(fn ($i) => Delivery::create(['tracking_number' => "TRK{$i}"]));
    $verbs = [...LK_VERBS, 'comment'];

    [$seedMs] = lk_time(function () use ($users, $customers, $deliveries, $verbs) {
        for ($i = 0; $i < 600; $i++) {
            $object = mt_rand(0, 4) === 0 ? $customers->random() : $deliveries->random();
            $activity = Storyfeed::activity($verbs[mt_rand(0, count($verbs) - 1)])
                ->actor($users->random())
                ->object($object)
                ->publishedAt(now()->subMinutes(mt_rand(0, 3 * 24 * 60)));

            if ($object instanceof Delivery) {
                $activity->target($customers->random());
            }

            $activity->publish();
        }
    });
    Artisan::call('storyfeed:curate');

    $sql = 0.0;
    $queries = 0;
    DB::listen(function ($query) use (&$sql, &$queries) {
        $sql += $query->time;
        $queries++;
    });

    foreach (['summary', 'log'] as $mode) {
        $samples = [];

        for ($rep = 0; $rep < 15; $rep++) {
            $sql = 0.0;
            $queries = 0;
            LkCountingManager::$calls = [];
            Delivery::$feedMediaCalls = 0;

            [$getMs, $page] = lk_time(fn () => Storyfeed::feed()->limit(50)->{$mode}()->get());
            $getSql = $sql;
            [$itemsMs, $items] = lk_time(fn () => $page->items());

            $samples[] = compact('getMs', 'getSql', 'itemsMs', 'items') + ['itemsSql' => $sql - $getSql, 'queries' => $queries, 'calls' => LkCountingManager::$calls];
        }

        usort($samples, fn ($a, $b) => ($a['getMs'] + $a['itemsMs']) <=> ($b['getMs'] + $b['itemsMs']));
        $median = $samples[intdiv(count($samples), 2)];
        $items = $median['items'];

        $groups = count(array_filter($items, fn ($item) => $item['kind'] === 'group'));
        $children = array_sum(array_map(fn ($item) => count($item['children'] ?? []), $items));
        $entities = 0;
        $walk = function (array $node) use (&$walk, &$entities) {
            foreach (['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'] as $role) {
                if (is_array($node[$role] ?? null)) {
                    $entities++;
                }
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        array_map($walk, $items);

        $total = $median['getMs'] + $median['itemsMs'];
        $sqlMs = $median['getSql'] + $median['itemsSql'];

        lk_out(sprintf(
            '  %-7s %2d nodes (%d groups, %d children), %d entities: %.2f ms total = SQL %.2f ms (%d queries) + PHP %.2f ms  [get() %.2f ms, items() %.2f ms]',
            $mode, count($items), $groups, $children, $entities, $total, $sqlMs, $median['queries'], $total - $sqlMs, $median['getMs'], $median['itemsMs'],
        ));
        lk_out('          registry reads: '.json_encode($median['calls']));
    }

    // Where items()' PHP goes: one summary page, stepped by hand.
    $retrieved = [];
    Event::listen('eloquent.retrieved: *', function (string $event) use (&$retrieved) {
        $class = class_basename(substr($event, strlen('eloquent.retrieved: ')));
        $retrieved[$class] = ($retrieved[$class] ?? 0) + 1;
    });
    $page = Storyfeed::feed()->limit(50)->summary()->get();
    $modelsInGet = $retrieved;
    $retrieved = [];
    $slices = (fn () => $this->slices)->call($page);
    $presenter = (fn () => $this->presenter)->call($page);
    $steps = ['forPage' => 0.0, 'headline' => 0.0, 'glyph+intent' => 0.0, 'tombstoneFact' => 0.0, 'entities' => 0.0, 'groupNode (all)' => 0.0];
    $reps = 20;
    for ($rep = 0; $rep < $reps; $rep++) {
        [$ms, $bound] = lk_time(fn () => $presenter->forPage($slices));
        $steps['forPage'] += $ms;
        foreach ($slices as $slice) {
            if ($slice->isGroup()) {
                [$ms] = lk_time(fn () => $bound->groupNode($slice));
                $steps['groupNode (all)'] += $ms;

                continue;
            }
            $activity = $slice->members->first();
            [$ms] = lk_time(fn () => (fn () => $this->headline($activity))->call($bound));
            $steps['headline'] += $ms;
            [$ms] = lk_time(fn () => [$manager->icon($activity->object_type, $activity->verb), $manager->glyphIntent($activity->object_type, $activity->verb)]);
            $steps['glyph+intent'] += $ms;
            [$ms] = lk_time(fn () => (fn () => $this->tombstoneFact($activity))->call($bound));
            $steps['tombstoneFact'] += $ms;
            [$ms] = lk_time(function () use ($bound, $activity) {
                foreach (['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'] as $role) {
                    (fn () => $this->entity($activity->{"{$role}_type"}, $activity->{"{$role}_id"}, $activity->{'cached'.ucfirst($role)}))->call($bound);
                }
            });
            $steps['entities'] += $ms;
        }
    }
    lk_out('  items() stepped (ms per page, mean of '.$reps.'):');
    foreach ($steps as $label => $ms) {
        lk_out(sprintf('    %-18s %6.2f', $label, $ms / $reps));
    }
    lk_out('  models hydrated by get(): '.json_encode($modelsInGet).'; by items() x'.$reps.': '.json_encode($retrieved));

    // Each primitive the presenter calls, in isolation.
    $n = 100_000;
    $rules = app(TombstoneRules::class);
    $feedables = app(Feedables::class);
    $context = fn () => new FeedContext(type: 'delivery', key: 1, label: 'x', data: [], feed: null, hydrator: new ModelHydrator, routeKey: null);
    $primitives = [
        'template(type.verb hit)' => fn () => $manager->template('delivery', 'ship'),
        'template(miss → *.*)' => fn () => $manager->template('nope', 'nope'),
        'icon(type.verb hit)' => fn () => $manager->icon('delivery', 'ship'),
        'glyphIntent(miss)' => fn () => $manager->glyphIntent('delivery', 'ship'),
        'constitutiveRoles()' => fn () => $rules->constitutiveRoles('delivery', 'ship'),
        'MorphResolver::classFor()' => fn () => MorphResolver::classFor('delivery'),
        'config() one key' => fn () => config('storyfeed.morph_alias'),
        'app(Feedables::class)' => fn () => app(Feedables::class),
        'isFeedable()' => fn () => $feedables->isFeedable(Delivery::class),
        'resolveSegments([ for :target])' => fn () => FeedHeadline::resolveSegments(':actor shipped :object[ for :target]', fn ($role) => true),
        'new FeedContext + ModelHydrator' => $context,
    ];

    lk_out('  primitives (µs per call):');
    foreach ($primitives as $label => $call) {
        lk_out(sprintf('    %-34s %6.3f', $label, lk_per_call($n, $call)));
    }

    lk_out(sprintf('  (seeding 600 publishes took %.0f ms)', $seedMs));

    expect(true)->toBeTrue();
});

it('3. publish: one publish, and a Request-injected action per publish', function () {
    lk_out("\n==== 3. PUBLISH ====");

    lk_fresh_manager();
    lk_define(30);

    $user = User::create(['name' => 'Dana', 'email' => 'dana@lk.test']);
    $customer = Customer::create(['name' => 'Acme']);
    $deliveries = collect(range(1, 50))->map(fn ($i) => Delivery::create(['tracking_number' => "P{$i}"]));

    $queries = 0;
    $sql = 0.0;
    DB::listen(function ($query) use (&$queries, &$sql) {
        $queries++;
        $sql += $query->time;
    });

    Storyfeed::activity('ship')->actor($user)->object($deliveries[0])->target($customer)->publish(); // warm

    $queries = 0;
    $sql = 0.0;
    LkCountingManager::$calls = [];
    $n = 300;
    [$ms] = lk_time(function () use ($n, $user, $customer, $deliveries) {
        for ($i = 0; $i < $n; $i++) {
            Storyfeed::activity('ship')->actor($user)->object($deliveries[$i % 50])->target($customer)->publish();
        }
    });
    lk_out(sprintf('  publish(): %.1f µs each, %.1f queries each, SQL %.1f µs of it (SQLite in memory, snapshots warm)', $ms * 1000 / $n, $queries / $n, $sql * 1000 / $n));
    lk_out('  registry reads per publish: '.json_encode(array_map(fn ($c) => $c / $n, LkCountingManager::$calls)));

    // The action, invoked the ways a per-publish implementation could.
    $app = Container::getInstance();
    app()->instance('request', Request::create('/orders', 'POST'));
    $story = new LkDeliveryStory;
    $builder = fn () => StoryDefinition::for(['delivery'], 'ship', LkDeliveryStory::class.'@ship');

    $reflected = new ReflectionMethod(LkDeliveryStory::class, 'ship');
    $params = array_map(fn (ReflectionParameter $p) => $p->getType()?->getName(), $reflected->getParameters());

    $n = 50_000;
    $ways = [
        'direct call, instance reused' => fn () => $story->ship($builder(), app('request')),
        'app()->call(), instance reused' => fn () => $app->call([$story, 'ship'], ['verb' => $builder()]),
        'app()->make() + app()->call()' => fn () => $app->call([$app->make(LkDeliveryStory::class), 'ship'], ['verb' => $builder()]),
        'reflection memoised, then direct' => function () use ($story, $params, $builder, $app) {
            $args = array_map(fn ($class) => $class === StoryDefinition::class ? $builder() : $app->make($class), $params);

            return $story->ship(...$args);
        },
        'builder alone (StoryDefinition::for)' => fn () => $builder(),
    ];

    lk_out('  a Story action per publish (µs per invocation):');
    foreach ($ways as $label => $call) {
        lk_out(sprintf('    %-40s %6.2f', $label, lk_per_call($n, $call)));
    }

    lk_out('    (BoundMethod::call reflects the method on every call: see Container/BoundMethod.php)');

    expect(true)->toBeTrue();
});
