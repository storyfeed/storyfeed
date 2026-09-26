<?php

/*
 * The words Storyfeed\Support\Headline and Entity use when the payload has
 * nothing to name. Core ships no wording in the payload; these lines are the
 * reader's, like any renderer's, and an app changes them by publishing them:
 *
 *     php artisan vendor:publish --tag=storyfeed-translations
 */
return [
    // A null actor: genuinely unknown. Also a degraded actor (no snapshot yet).
    'someone' => 'Someone',

    // Any other role with nothing to name.
    'something' => 'Something',

    // A deleted model. `:type` is its former morph alias as a noun
    // ("line_item" → "line item"); a kept label is shown instead.
    'former' => 'a former :type',
    'removed' => 'a removed :type',
    'item' => 'item',

    // Lists: "Ana, Ben and 3 more".
    'and' => 'and',
    'more' => '{1} :count more|[2,*] :count more',
    'others' => '{1} :count other|[2,*] :count others',

    // A group with no headline: a count, never prose borrowed from one member.
    'activities' => '{1} :count activity|[2,*] :count activities',

    // An activity with no headline. The bracketed part is dropped when the
    // activity has no object.
    'unnamed' => ':actor :verb[ :object]',

    // A digest phrase with no headline.
    'phrase' => ':verb (:count)',
];
