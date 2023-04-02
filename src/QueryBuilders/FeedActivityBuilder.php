<?php

namespace Storyfeed\QueryBuilders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeedActivityBuilder extends Builder
{
    public function published()
    {
        return $this->whereNotNull(
            $this->qualifyColumn('published_at')
        );
    }

    public function containsObject($type, $id)
    {
        return $this->where(function ($query) use ($type, $id) {
            $query->orWhere(function ($q) use ($type, $id) {
                return $q
                    ->where($this->qualifyColumn('actor_type'), $type)
                    ->where($this->qualifyColumn('actor_id'), $id);
            });

            $query->orWhere(function ($q) use ($type, $id) {
                return $q
                    ->where($this->qualifyColumn('object_type'), $type)
                    ->where($this->qualifyColumn('object_id'), $id);
            });

            $query->orWhere(function ($q) use ($type, $id) {
                return $q
                    ->where($this->qualifyColumn('target_type'), $type)
                    ->where($this->qualifyColumn('target_id'), $id);
            });
        });
    }

    public function actor(Model $model)
    {
        return $this
            ->where($this->qualifyColumn('actor_type'), $model->getMorphClass())
            ->where($this->qualifyColumn('actor_id'), $model->getKey());
    }

    public function object(Model $model)
    {
        return $this
            ->where($this->qualifyColumn('object_type'), $model->getMorphClass())
            ->where($this->qualifyColumn('object_id'), $model->getKey());
    }

    public function target(Model $model)
    {
        return $this
            ->where($this->qualifyColumn('target_type'), $model->getMorphClass())
            ->where($this->qualifyColumn('target_id'), $model->getKey());
    }

    public function involving($model)
    {
        return $this->where(function ($query) use ($model) {
            $query->orWhere->actor($model)
                ->orWhere->object($model)
                ->orWhere->target($model);
        });
    }
}
