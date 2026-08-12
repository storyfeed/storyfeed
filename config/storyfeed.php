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
        'parties' => 'feed_parties',
        'batches' => 'feed_batches',
        'meta' => 'feed_meta',
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
    | AS2.0 Routes
    |--------------------------------------------------------------------------
    |
    | Opt-in, read-only Activity Streams 2.0 endpoints:
    |
    |   GET /{prefix}/activities/{uid}   single Activity document
    |   GET /{prefix}/feed               OrderedCollection (cursor param)
    |
    | Off by default — exposing a feed is an app decision. Add auth or
    | throttling via the middleware array. The prefix also mints activity
    | IRIs, so changing it changes document ids.
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
    ],

];
