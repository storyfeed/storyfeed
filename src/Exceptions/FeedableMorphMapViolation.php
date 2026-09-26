<?php

namespace Storyfeed\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class FeedableMorphMapViolation extends LogicException
{
    public static function forModel(Model $model): self
    {
        $class = $model::class;
        $alias = Str::snake(class_basename($model));

        return new self(
            "Feedable model [{$class}] requires a morph alias because Storyfeed::requireFeedableMorphMap() is enabled. "
            ."Add Relation::morphMap(['{$alias}' => \\{$class}::class]); in your service provider's boot() method."
        );
    }
}
