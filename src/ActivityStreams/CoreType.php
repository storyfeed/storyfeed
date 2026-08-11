<?php

namespace Storyfeed\ActivityStreams;

use Storyfeed\ActivityStreams\Concerns\IsVocabularyTerm;

/**
 * The Activity Streams 2.0 core types.
 *
 * `Object` and `Activity` intentionally also appear on ObjectType /
 * conceptually relate to ActivityType — these are value sets, not a class
 * hierarchy, and PHP enums may share backing values freely. Don't "fix" it.
 *
 * @see https://www.w3.org/TR/activitystreams-vocabulary/#types
 */
enum CoreType: string implements VocabularyTerm
{
    use IsVocabularyTerm;

    case Object = 'Object';
    case Link = 'Link';
    case Activity = 'Activity';
    case IntransitiveActivity = 'IntransitiveActivity';
    case Collection = 'Collection';
    case OrderedCollection = 'OrderedCollection';
    case CollectionPage = 'CollectionPage';
    case OrderedCollectionPage = 'OrderedCollectionPage';
}
