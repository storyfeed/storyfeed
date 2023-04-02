<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Storyfeed\ActivityFeed;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Contracts\Storyable;
use Storyfeed\QueryBuilders\FeedActivityBuilder;
use Storyfeed\Story;

class FeedActivity extends Model implements Storyable
{
    use HasTimestamps, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'data' => AsArrayObject::class,
    ];

    protected $appends = [
        'summary',
        'headline',
    ];

    public function toStory(): Story
    {
        return (new Story())
            ->type($this->type)
            ->actor($this->actor_type, $this->actor_id)
            ->object($this->object_type, $this->object_id)
            ->target($this->target_type, $this->target_id)
            ->publishedAt($this->published_at);
    }

    public function newEloquentBuilder($query)
    {
        return new FeedActivityBuilder($query);
    }

    public static function query(): FeedActivityBuilder
    {
        return parent::query();
    }

    public static function booted()
    {
        static::creating(function ($model) {
            if (! $model->actor) {
                $model->actor()->associate(Auth::user());
            }
        });

        static::saved(function ($model) {
            $model->setGroupings();
        });

        static::saving(function ($model) {
            if (! $model->published_at) {
                $model->published_at = now();
            }
        });
    }

    public static function make(array $attributes = []): static
    {
        return new static($attributes);
    }

    public function grouping()
    {
        return $this->hasOne(FeedGroup::class, 'activity_id')
            ->whereContext(null);
    }

    public function actor()
    {
        return $this->morphTo();
    }

    public function object()
    {
        return $this->morphTo();
    }

    public function target()
    {
        return $this->morphTo();
    }

    public function result()
    {
        return $this->morphTo();
    }

    public function setGroupings(): void
    {
        FeedGroup::assign($this->id, $this->group_signature);
    }

    public function getGroupSignatureAttribute()
    {
        $hash = "{$this->actor_id}:{$this->type}:{$this->object_type}";

        if ($this->target_type) {
            $hash .= ":{$this->target_type}";
        }

        if ($this->target_id) {
            $hash .= ":{$this->target_id}";
        }

        if ($date = $this->published_at?->format('Y-m-d')) {
            $hash .= ":$date";
        }

        return $hash;
    }

    public function getSummaryAttribute()
    {
        return 'Actor did something with object in target';
    }

    public function getLabelsAttribute()
    {
        $labels = [
            'actor' => $this->actor_id,
            'object' => $this->object_id,
            'target' => $this->target_id,
        ];

        if ($this->actor instanceof PublishesToFeed) {
            $labels['actor'] = $this->actor->feedLabel();
        }

        if ($this->object instanceof PublishesToFeed) {
            $labels['object'] = $this->object->feedLabel();
        }

        if ($this->target instanceof PublishesToFeed) {
            $labels['target'] = $this->target->feedLabel();
        }

        return $labels;
    }

    public function getHeadlineAttribute()
    {
        $grammar = ActivityFeed::grammar();
        $objectMap = data_get($grammar, $this->object_type);

        if (is_callable($objectMap)) {
            $message = $objectMap($this);
        } else {
            $message = data_get($objectMap, $this->type);
        }

        if (! $message) {
            return null;
        }

        if (is_callable($message)) {
            $message = $message($this);
        } else {
            $message = data_get($message, $this->type);
        }

        return $message;
    }
}
