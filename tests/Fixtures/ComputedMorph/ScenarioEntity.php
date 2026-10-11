<?php

namespace Storyfeed\Tests\Fixtures\ComputedMorph;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;

/**
 * A Feedable that works out its morph class from its attributes, so a blank
 * instance can't answer: `kind` is null until the row is loaded.
 *
 * @property string|null $kind
 */
class ScenarioEntity extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'customers';

    public function getMorphClass(): string
    {
        return $this->kind;
    }
}
