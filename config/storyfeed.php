<?php

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
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
    | Grouping
    |--------------------------------------------------------------------------
    |
    | The strategy computing candidate grouping hashes at publish time.
    | Use NullStrategy to disable grouping entirely.
    |
    */

    'grouping' => [
        'strategy' => MultiAxisStrategy::class,
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
