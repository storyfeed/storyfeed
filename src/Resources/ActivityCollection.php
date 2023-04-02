<?php

namespace Storyfeed\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ActivityCollection extends ResourceCollection
{
    public $collects = ActivityResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'data' => $this->collection
                ->groupBy('family_hash')
                ->map(function ($items, $hash) {
                    $activity = $items->shift();

                    data_set($activity, 'children', $items);

                    return $activity;
                })
                ->values(),
        ];
    }
}
