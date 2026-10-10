<?php

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Meta;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;

return [

    // Maximum parent links captured per object, target or context (0–255). Actors never walk.
    'ancestors' => ['max_depth' => 10],

    /*
    |--------------------------------------------------------------------------
    | Definitions File
    |--------------------------------------------------------------------------
    |
    | The file that holds what each activity says, the way routes/web.php
    | holds routes: `Story::for(Document::class)->verb('upload')->headline(…)`.
    | `php artisan storyfeed:install` creates it. It is loaded after every
    | service provider has booted, so your morph map is already in place.
    |
    | `php artisan storyfeed:cache` caches it the way `route:cache` caches
    | route files: once cached, the file isn't loaded at all. Point this
    | elsewhere if your app already has a routes/feed.php, or set it to false
    | to turn loading off.
    |
    */

    'definitions' => base_path('routes/feed.php'),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Where a feed's activities come from, named the way filesystem disks are:
    | `Storyfeed::feed()->source('changelog')`. The database is the default
    | and needs no entry. The `array` driver reads static items from config;
    | register your own drivers with Storyfeed::extend('github', fn ($app,
    | array $config) => new GitHubSource($config)).
    |
    | A source other than the database is read in memory and cannot answer
    | involving(), involvingType() or query(): those throw on one.
    |
    */

    'sources' => [
        'database' => ['driver' => 'database'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | Remap any table if the defaults collide with existing tables in your
    | application, or to point Storyfeed at pre-existing feed tables.
    |
    */

    'tables' => [
        'activities' => 'feed_activities',
        'snapshots' => 'feed_snapshots',
        'groupings' => 'feed_groupings',
        'grouping_bursts' => 'feed_grouping_bursts',
        'participants' => 'feed_participants',
        'parties' => 'feed_parties',
        'batches' => 'feed_batches',
        'meta' => 'feed_meta',
        'tombstones' => 'feed_tombstones',
        'batch_locks' => 'feed_batch_locks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | The switch. Off, every publish() — the builder, Storyfeed::record(),
    | Story classes, PublishesToFeed events, `->publish()` on a verb enum —
    | composes its Activity and returns it UNSAVED, writes nothing to any of
    | the seven tables, dispatches no ActivityPublished, and throws nothing.
    | Feedable models stop refreshing their snapshots on save. Reads are
    | untouched: a feed page renders whatever is already there.
    |
    | ON EVERYWHERE, BY DEFAULT — including under `testing`. A feed that
    | silently records nothing in one environment is the "green in tests,
    | empty in production" class of bug, and it would break every feature
    | test that renders a feed page. Mute a suite explicitly, in phpunit.xml:
    |
    |   <env name="STORYFEED_RECORDING_ENABLED" value="false"/>
    |
    | …then opt the tests that assert on the feed back in with the
    | `Storyfeed\Testing\RecordsStories` trait, or Storyfeed::startRecording().
    | The runtime toggles (stopRecording / startRecording / withoutRecording /
    | recording) override this for the current process. See docs/testing.md,
    | "Quiet suites". `storyfeed:doctor` warns when this is off anywhere
    | other than testing.
    |
    */

    'recording' => [
        'enabled' => env('STORYFEED_RECORDING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshots
    |--------------------------------------------------------------------------
    |
    | When `toFeed()` output is recompiled, configured like QUEUE_CONNECTION.
    |
    | `cached` (production): `php artisan optimize` recompiles the snapshots
    | of the newest 1,000 activities (`storyfeed:cache-snapshots`), and the
    | trickle catches up the rest.
    |
    | `sync` (local): set STORYFEED_SNAPSHOTS=sync and `toFeed()` changes show
    | on reload. When a Feedable model or Story class file changes, the next
    | feed read runs that same pass once, as Blade recompiles an edited view.
    | Rows still show each entity as it was then. `storyfeed:install` writes
    | the line to your .env, and the doctor warns about it outside local and
    | testing.
    |
    */

    'snapshots' => [
        'compile' => env('STORYFEED_SNAPSHOTS', 'cached'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap in your own model classes; they should extend the defaults.
    |
    */

    'models' => [
        'activity' => Activity::class,
        'snapshot' => Snapshot::class,
        'grouping' => Grouping::class,
        'party' => Party::class,
        'batch' => Batch::class,
        'meta' => Meta::class,
        'tombstone' => FeedTombstone::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Party Morph Alias
    |--------------------------------------------------------------------------
    |
    | The morph alias stored for feed parties. Resolved independently of the
    | application's morph map, so enforceMorphMap() cannot break it.
    |
    */

    'morph_alias' => 'storyfeed.party',

    /*
    |--------------------------------------------------------------------------
    | Parties
    |--------------------------------------------------------------------------
    |
    | A fallback party name used when no actor can otherwise be resolved —
    | typically for activities published from queued jobs or console
    | commands. Null keeps those activities anonymous.
    |
    | Once Storyfeed::parties([...]) declares the names an actor may take, an
    | undeclared name (a verb's ->actor(), Storyfeed::actor()) throws when
    | `strict`, and is otherwise ignored: the activity keeps the actor it
    | would have had, and storyfeed:doctor names it. Null means strict in
    | local/testing only, as verbs.strict does.
    |
    */

    'parties' => [
        'fallback' => null,
        'strict' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Morph Map
    |--------------------------------------------------------------------------
    |
    | Entries here are merged into the application's morph map at boot.
    | Storyfeed stores morph aliases on activity role columns, so feed
    | entities should have stable aliases.
    |
    */

    'morph_map' => [],

    /*
    |--------------------------------------------------------------------------
    | Verbs
    |--------------------------------------------------------------------------
    |
    | Verbs are free-form strings. Strict mode is a development-time
    | assertion, not a storage constraint: when enabled, recording a verb
    | that resolves to no registry entry throws instead of silently
    | creating a typo'd activity.
    |
    */

    'verbs' => [
        // null: strict in local/testing, permissive everywhere else.
        // Set true/false to decide explicitly.
        'strict' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | The strategy computing candidate grouping hashes at publish time.
    | Use NullStrategy to disable grouping entirely.
    |
    | children_limit caps the member activities nested in one group node.
    | The node's `count` stays the true total; `children_truncated` tells
    | the renderer when it is looking at a capped list.
    |
    */

    'grouping' => [
        'strategy' => MultiAxisStrategy::class,
        'children_limit' => 25,

        // App-wide sample limits, keyed by singular role. Set object to 6
        // to show six objects while every other role stays at three. These
        // draw from the loaded children; children_limit still bounds them.
        // Missing or invalid limits fall back to 3; use positive integers.
        'sample_limits' => [
            'actor' => 3,
            'object' => 3,
            'target' => 3,
            'context' => 3,
            'origin' => 3,
            'result' => 3,
            'instrument' => 3,
            'location' => 3,
            'generator' => 3,
        ],

        /*
        | Curation selects ONE winning axis per activity, inline with the
        | publish transaction. The policy is distinct cardinality on the
        | dimension each axis collapses — never "largest cluster wins",
        | which is a coin flip between repeat and targets. Ties break by
        | axis priority (actors > actors_target > targets > object > repeat), then hash.
        |
        | Policy is not payload contract: change it freely (docs/payload.md).
        */
        /*
        | The app-wide default read mode: 'live' (one-action bursts) or 'log'
        | (the atomic timeline). Explicit ->live() / ->log() override it.
        | Summary is retired; the old modes throw with the replacement name.
        */
        'default' => 'live',

        'curate' => true,
        'policy' => [
            'min_actors' => 3,
            'min_targets' => 2,
            'min_target_members' => 3,
            'min_object_members' => 2,
        ],

        // A row absorbs the same action until the quiet gap or ceiling.
        // Per verb: Story::verb('comment')->bursts(within: '5 minutes', ceiling: '1 hour').
        'bursts' => [
            'within' => '15 minutes',
            'ceiling' => '4 hours',
        ],

        /*
        | Batches: bursts of activity by one actor, inferred by a sliding
        | quiet window — recorded automatically, invisible to the recording
        | code. Batches are queryable and BatchClosed is the digest hook.
        | They reach the feed through composites: when a batch closes, its
        | runs of Bundleable objects become one story (see 'composite'
        | below). Stale batches close lazily at the actor's next publish;
        | schedule storyfeed:close-batches for prompt BatchClosed delivery.
        |
        | Batching is the `batch` story middleware, in the `default` group.
        | quiet_minutes is its window when a verb gives none; a verb can give
        | its own (->batched(within: '5 minutes')) or opt out (->unbatched()).
        */
        'batch' => [
            'enabled' => true,
            'quiet_minutes' => 10,
        ],

        /*
        | Composites: one authored story whose object is a collection —
        | "Tomás uploaded 6 files to Spring Campaign". Explicit via
        | ->objects([...]); AUTO-BUNDLED from atomically-recorded runs of
        | Bundleable-designated types when the actor's batch closes
        | (requires batches enabled). min_objects is the smallest DISTINCT
        | object count that mints a story — singles stay atomic (the
        | collection-of-one collapse).
        */
        'composite' => [
            'auto' => true,
            'min_objects' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Hydration
    |--------------------------------------------------------------------------
    |
    | Whether a resolver may load its live model through $context->model().
    | On, the cost is one query per class per page: the presenter seeds an
    | identity map with every entity the page holds, and the first entity of
    | a class to ask loads the whole class. A resolver that never calls it
    | costs nothing either way.
    |
    | Off, model() returns null — silently, with no query and no exception —
    | for an application that needs a no-queries guarantee on a hot surface.
    | Links minted from the model degrade to the resolver's null branch; the
    | activity still renders. The read path never throws over this.
    |
    */

    'hydration' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | AS2.0 Routes
    |--------------------------------------------------------------------------
    |
    | Opt-in, read-only Activity Streams 2.0 endpoint:
    |
    |   GET /{prefix}/activities/{uid}   single Activity document
    |
    | Off by default — exposing a feed is an app decision. Add auth or
    | throttling via the middleware array. The prefix also mints activity
    | IRIs, so changing it changes document ids.
    |
    | There is no collection route. GET /{prefix}/feed was removed at
    | v0.8.0-alpha.2: it served every published activity in the system,
    | unscoped and with no verb allowlist. It returns when a named feed can
    | back it. Serializing a collection is still supported.
    |
    */

    'routes' => [
        'enabled' => false,
        'prefix' => 'storyfeed',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Actor Resolver
    |--------------------------------------------------------------------------
    |
    | How to resolve the default actor when publishing an activity without
    | an explicit actor. Accepts an invokable class name. When null, the
    | authenticated user is used. Activities without any resolvable actor
    | are published as anonymous.
    |
    */

    'actor_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Keep latest
    |--------------------------------------------------------------------------
    |
    | What a verb that declares `->keepLatest()` in routes/feed.php does to
    | the rows each publish supersedes. Superseding is the shape for
    | repeatable verbs — a status tick, a re-save — where only the latest
    | row should read as the story.
    |
    | 'soft' (the default) SOFT-deletes the superseded rows: they leave the
    | feed and every query the package makes, but stay in the activities
    | table with `deleted_at` set. Deliberate, not an accident of the trait:
    | a superseded activity is history, and history is kept — "this was true
    | and is not any more" is a fact about the world, and an audit wants it.
    | Their participant rows are removed; their grouping rows stay, inert,
    | because curation and the read path only ever reach groupings through
    | the live-activity query. `storyfeed:prune` retires them with the rest.
    | A backdated publish older than a live row is stored soft-deleted.
    |
    | 'force' HARD-deletes them, grouping rows and participant rows included,
    | inside the publish transaction. For an app where a busy repeatable verb
    | would otherwise accumulate soft-deleted rows for the life of the table
    | and nothing ever reads them back. A backdated publish older than a
    | live row is not written at all. Nothing else is touched: snapshots
    | are per-entity, and a batch's `activities_count` is a running total of
    | what was recorded, under either setting.
    |
    | Any other value throws at publish time rather than guessing.
    |
    */

    'keep_latest' => [
        'delete' => 'soft',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | `storyfeed:prune` permanently deletes activities older than their
    | verb's window, with the snapshots and tombstones only they named, and
    | what remains of each group is re-decided ("viewed 12 documents" becomes
    | "viewed 3"). Nothing is scheduled for you; `--pretend` shows a run.
    |
    | `after_days` is the window for every verb that declares none. Null (the
    | default) keeps them. A verb's own window wins in either direction:
    |
    |     Story::verb('view')->keepFor('30 days');
    |     Story::verb('sign')->keepForever();   // exempt from after_days
    |
    */

    'prune' => [
        'after_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Curation Repair
    |--------------------------------------------------------------------------
    |
    | Hourly curation repair, with overlap protection. Laravel's scheduler must
    | be running. Disable `schedule` if you own the schedule or do not want
    | repair. This does not control inline curation.
    |
    | `window` bounds the SCHEDULED run to activities published in the last N
    | days. Running `php artisan storyfeed:curate` by hand is unaffected: no
    | flag still means the whole table, because that is the explicit "do
    | everything" and an operator asking for it should get it.
    |
    | WHY TWO DAYS. Built-in Live bursts close within four hours by default.
    | Two days covers those memberships and delayed repair. If a configured
    | burst ceiling exceeds this horizon, raise the window to cover it.
    |
    | A CUSTOM CALENDAR AXIS with `:d` honors `->groupedWeekly()` or
    | `->groupedMonthly()`, putting its week or month in that segment, so its
    | clusters stay open for the whole period. The scheduled run looks back over
    | that verb's period plus a day (8 days for a week, 32 for a month), for that
    | verb only. Nothing to set here: it follows the declarations.
    |
    | WHEN TWO DAYS IS WRONG. A CUSTOM AXIS WHOSE KEY DOES NOT PIN THE DAY has
    | no closed clusters — a group can gain a member weeks after it formed, and
    | the scheduled run has to be able to see it. Register one and raise this to
    | cover the age of activity that can still join a cluster, or set it to
    | `null` (or `0`) for the unbounded hourly pass this package shipped before.
    |
    | Rows that never went through the publish path — a bulk import converged by
    | `storyfeed:trickle`, or history predating an axis you have since added —
    | are not curated by publish and may be older than any window. Run
    | `php artisan storyfeed:curate` with no flags after an import or an axis
    | change. `storyfeed:doctor` reports them as `grouping.uncurated`.
    */

    'curate' => [
        'schedule' => true,
        'window' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot Trickle
    |--------------------------------------------------------------------------
    */

    'trickle' => [
        'limit' => 200,

        /*
         * DELETE activities whose feed roles cannot be resolved.
         *
         * Off by default, and deliberately: an unresolvable role is nearly
         * always a model that is not `Feedable` yet — a bug in the app — and
         * deleting the evidence of a bug is a poor way to report it. One
         * consumer discovered that EVERY activity their operator performed had
         * an unresolvable actor for exactly that reason; with pruning on, a
         * worker documented as "snapshot convergence" would have removed the
         * lot on its next scheduled run.
         *
         * Left off, the trickle COUNTS them instead (`unresolved` in its
         * output, and in `storyfeed:doctor`), and keeps snapshotting the rows
         * behind them. Turn it on when orphans are genuinely garbage — an
         * import that referenced rows you will never load — and not before.
         */
        'prune' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Doctor
    |--------------------------------------------------------------------------
    |
    | `stale_after` (days) is the "has the feed stopped keeping up?" check.
    | The failure it exists for is not a broken feed but a forgotten one: the
    | grammar gets authored once, new modules ship, and nothing publishes from
    | them. Every other check asks whether what you have is correct; this one
    | asks whether anything is still arriving. Set null to disable.
    |
    | Honest about its reach: a module that never touches Storyfeed at all is
    | invisible to Storyfeed. This is the closest available proxy, not a proof
    | — `storyfeed:stories` covers the part that IS detectable.
    */

    'doctor' => [
        'stale_after' => 30,

        // Deliberate grammar gaps: exact code + complete subject from doctor JSON.
        // Each entry is ['code' => 'grammar.missing', 'subject' =>
        // ['type' => 'delivery', 'verb' => 'confirm'], 'reason' => 'Rendered by the app.'].
        // Supported: grammar.missing, grammar.icon_missing, aggregates.missing,
        // axes.verbless_no_grammar. No patterns or partial subjects.
        'acknowledgments' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Grammar
    |--------------------------------------------------------------------------
    |
    | Throw when publishing a (type, verb) with no headline authored, instead
    | of letting the feed render a blank line. A development-time assertion
    | like verbs.strict: null means strict in local/testing only, and
    | production always publishes.
    |
    | This is the earliest place the "grammar was authored once and never grew"
    | failure can be caught — HeadlineCoverage catches it in CI and doctor
    | catches it at runtime, but both need someone to look. This fires where
    | the publish call is written.
    */

    'grammar' => [
        'strict' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Surface Discovery
    |--------------------------------------------------------------------------
    |
    | Where `storyfeed:stories` and doctor's `surface` check look for declared
    | feed surface — Feedable models, PublishesToFeed implementors, Story
    | classes. Null scans app_path().
    |
    | This is a DEV-TIME scan only: nothing at boot depends on it, which is what
    | keeps registration explicit. Narrow it if your app is large.
    */

    'discovery' => [
        'paths' => null,
    ],

];
