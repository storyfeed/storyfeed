<?php

use Storyfeed\Foundation\FeedGrammar;

it('can translate acitivities using defined grammar', function () {
    $grammar = [
        'create:delivery' => ':actor created a delivery',
        'update:delivery' => ':actor updated a delivery',
        'submit:delivery' => ':actor submitted a delivery',
        'confirm:delivery' => ':actor confirmed a delivery',
        'status:delivery' => ':actor changed the status of a delivery',
    ];

    FeedGrammar::define([
        'delivery' => function ($activity) {
            $actor = data_get($activity, 'actor.name', 'Someone');
            $context = $activity->object->category_str ?? 'Delivery';

            return [
                'created' => __(':actor created :context', [
                    'actor' => $actor,
                    'context' => $context,
                ]),
                'updated' => __(':actor updated :context', [
                    'actor' => $actor,
                    'context' => $context,
                ]),
                'confirm' => __(':actor confirmed :context #:id', [
                    'actor' => $actor,
                    'context' => $context,
                    'id' => $activity->object_id,
                ]),
                'submit' => __(':actor submitted :context #:id', [
                    'actor' => $actor,
                    'context' => $context,
                    'id' => $activity->object_id,
                ]),
                'status' => __(':actor updated the status of :context #:id', [
                    'actor' => $actor,
                    'context' => $context,
                    'id' => $activity->object_id,
                ]),
            ];
        },
    ]);
});
