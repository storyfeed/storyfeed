<?php

namespace Storyfeed\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedEntity;
use Storyfeed\Support\MorphResolver;

class NestedContainer extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'nested_containers';

    protected $guarded = [];

    public static function install(): void
    {
        Schema::create('nested_containers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('parent_type')->nullable();
            $table->string('parent_id', 36)->nullable();
            $table->timestamps();
        });
    }

    public function toFeed(): FeedEntity
    {
        $parent = $this->parent_type === null ? null : MorphResolver::feedable($this->parent_type, $this->parent_id);

        return FeedEntity::make($this->name)->parent($parent);
    }
}
