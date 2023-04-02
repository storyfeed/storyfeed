<?php

namespace Storyfeed\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'actor' => $this->whenLoaded(
                'actor',
                ObjectResource::make($this->actor)->toArray($request)
            ),

            'object' => $this->whenLoaded(
                'object',
                ObjectResource::make($this->object)->toArray($request)
            ),

            'target' => $this->whenLoaded(
                'target',
                ObjectResource::make($this->target)->toArray($request)
            ),
        ] + parent::toArray($request);
    }
}
