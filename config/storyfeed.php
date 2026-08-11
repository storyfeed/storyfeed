<?php

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
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
