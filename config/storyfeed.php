<?php

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;

return [

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
        'participants' => 'feed_participants',
        'parties' => 'feed_parties',
        'batches' => 'feed_batches',
        'meta' => 'feed_meta',
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
    */

    'parties' => [
        'fallback' => null,
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

        /*
        | Curation selects ONE winning axis per activity, inline with the
        | publish transaction. The policy is distinct cardinality on the
        | dimension each axis collapses — never "largest cluster wins",
        | which is a coin flip between repeat and targets. Ties break by
        | axis priority (actors > targets > repeat), then hash.
        |
        | Policy is not payload contract: change it freely (docs/payload.md).
        */
        /*
        | The app-wide default read mode: 'log' (the atomic timeline), 'live'
        | (repeat-only, the classic behaviour), or 'summary' (multi-axis
        | winners). Per-view calls (->log() / ->live() / ->summary()) always
        | override.
        |
        | Renamed in v0.7 from flat/grouped/curated — the old `curated` claimed
        | editorial judgement over what is mechanical collapsing, and the name
        | misled people into choosing it for the wrong reason. `curated` is
        | reserved for a future relevance-RANKED view. The old values now throw
        | with the new name rather than falling back to a default.
        */
        'default' => 'summary',

        'curate' => true,
        'policy' => [
            'min_actors' => 3,
            'min_targets' => 2,
            'min_target_members' => 3,
            'min_object_members' => 2,
        ],

        /*
        | Batches: bursts of activity by one actor, inferred by a sliding
        | quiet window — recorded automatically, invisible to the recording
        | code. Infrastructure only: batches are queryable and BatchClosed
        | is the digest hook, but they do not (yet) participate in feed
        | grouping — see docs/grouping.md, "the composite-activity open
        | problem". Stale batches close lazily at the actor's next publish;
        | schedule storyfeed:close-batches for prompt BatchClosed delivery.
        */
        'batch' => [
            'enabled' => true,
            'quiet_minutes' => 10,
        ],

        /*
        | Composites: one authored story whose object is a collection —
        | "Tomás uploaded 6 files to Spring Campaign". Explicit via
        | ->objects([...]); AUTO-BUNDLED from atomically-recorded runs of
        | Collectable-designated types when the actor's batch closes
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
    | Replace
    |--------------------------------------------------------------------------
    |
    | What `->replace()` / `publishAndReplace()` does to the rows it
    | supersedes. Superseding is the shape for repeatable verbs — a status
    | tick, a re-save — where only the latest row should read as the story.
    |
    | 'soft' (the default) SOFT-deletes the superseded rows: they leave the
    | feed and every query the package makes, but stay in the activities
    | table with `deleted_at` set. Deliberate, not an accident of the trait:
    | a superseded activity is history, and history is kept — "this was true
    | and is not any more" is a fact about the world, and an audit wants it.
    | Their participant rows are removed; their grouping rows stay, inert,
    | because curation and the read path only ever reach groupings through
    | the live-activity query. `storyfeed:prune` retires them with the rest.
    |
    | 'force' HARD-deletes them, grouping rows and participant rows included,
    | inside the publish transaction. For an app where a busy repeatable verb
    | would otherwise accumulate soft-deleted rows for the life of the table
    | and nothing ever reads them back. Nothing else is touched: snapshots
    | are per-entity, and a batch's `activities_count` is a running total of
    | what was recorded, under either setting.
    |
    | Any other value throws at publish time rather than guessing.
    |
    */

    'replace' => [
        'delete' => 'soft',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    */

    'prune' => [
        'after_days' => null,
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
    | failure can be caught — GrammarCoverage catches it in CI and doctor
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

    /*
    |--------------------------------------------------------------------------
    | Demo Data
    |--------------------------------------------------------------------------
    |
    | Enable the vocabulary `storyfeed:demo` seeds with — its verbs, grammar,
    | aggregate grammar and icons — so a seeded demo RENDERS.
    |
    | This is separate from seeding on purpose. The seeder runs in an artisan
    | process; every process that shows the demo is a different one, and grammar
    | registered only by the seeder leaves group nodes with null headlines in
    | exactly the surfaces you meant to demo. Off by default because these verbs
    | are noise in an application's own registry and in doctor's feed coverage.
    |
    | Turn it on in the environment doing the demo, not in production — wire it
    | to an env var of your own here if you want it switchable per environment.
    | See docs/demo-data.md for why the package seeds a world rather than
    | shipping a redactor.
    */

    'demo' => [
        'enabled' => false,
    ],

];
