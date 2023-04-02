<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Storyfeed\Models\FeedActivity;

class ActivityBuilder
{
    public FeedActivity $activity;

    public $replace = false;

    public function __construct(...$args)
    {
        $this->activity = new FeedActivity(...$args);
    }

    public static function make(...$args)
    {
        return new static(...$args);
    }

    public function __call($name, $args): static
    {
        return $this->type($name, ...$args);
    }

    public function data(array $data)
    {
        $this->activity->data = $data;

        return $this;
    }

    public function type(string $type, $object = null)
    {
        $this->activity->type = $type;

        if ($object) {
            $this->object($object);
        }

        return $this;
    }

    public function publishAt($date)
    {
        $this->activity->published_at = $date;

        return $this;
    }

    public function when($date)
    {
        return $this->publishAt($date);
    }

    public function on($date)
    {
        return $this->publishAt($date);
    }

    public function actor($model = null)
    {
        if ($model instanceof Model) {
            $this->activity->actor()->associate($model);
        }

        return $this;
    }

    public function object($model = null)
    {
        if ($model instanceof Model) {
            $this->activity->object()->associate($model);
        }

        return $this;
    }

    public function in(...$args)
    {
        return $this->target(...$args);
    }

    public function to(...$args)
    {
        return $this->target(...$args);
    }

    public function for(...$args)
    {
        return $this->target(...$args);
    }

    public function from(...$args)
    {
        return $this->target(...$args);
    }

    public function target($model = null)
    {
        if ($model instanceof Model) {
            $this->activity->target()->associate($model);
        }

        return $this;
    }

    public function result($model = null)
    {
        if ($model instanceof Model) {
            $this->activity->result()->associate($model);
        }

        return $this;
    }

    public function replace($replace)
    {
        $this->replace = $replace;

        return $this;
    }

    public function publishAndReplace()
    {
        $this->replace(true);

        return $this->publish();
    }

    public function update()
    {
        return $this->publishAndReplace();
    }

    public function publish()
    {
        return DB::transaction(function () {
            $this->activity->save();

            if ($this->replace && $this->activity->object) {
                FeedActivity::query()
                    ->whereNot('id', $this->activity->id)
                    ->object($this->activity->object)
                    ->where('type', $this->activity->type)
                    ->delete();
            }

            return $this->activity;
        });
    }
}
