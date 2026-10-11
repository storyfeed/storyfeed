<?php

namespace Workbench\App\Docs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;

/**
 * A thing in the docs world (a venue, an order, a user), as an app's model.
 *
 * Each entity type the world names gets its own class and table, declared
 * by {@see define()}: `order` is `Workbench\App\Docs\World\Order`, morph
 * alias `order`, table `docs_orders`. The world is written in the docs, so
 * its types are too; a class per type keeps every type's keys apart, as an
 * app's models do, without a file here for each.
 *
 * toFeed() caches what the thing is (its label, data and body). feedMedia()
 * resolves its link and pictures from the live row at read time, through
 * `$context->model()`, so a deleted thing loses both, as it would in an app.
 *
 * @property string $id
 * @property string $label
 * @property string|null $url
 * @property array<string, mixed>|null $data
 * @property list<array<string, mixed>>|null $body
 * @property array<string, mixed>|null $media
 *
 * @phpstan-consistent-constructor
 */
abstract class WorldModel extends Model implements Feedable
{
    use InteractsWithFeed;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @var array<string, class-string<WorldModel>> */
    protected static array $defined = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'body' => 'array', 'media' => 'array'];
    }

    /**
     * The model class for one entity type: declared the first time it is asked
     * for, and mapped to the type as its morph alias.
     *
     * @return class-string<WorldModel>
     */
    public static function define(string $type): string
    {
        if (isset(static::$defined[$type])) {
            return static::$defined[$type];
        }

        $name = Str::studly($type);
        $class = __NAMESPACE__."\\World\\{$name}";

        if (! class_exists($class, false)) {
            eval('namespace '.__NAMESPACE__."\\World; final class {$name} extends \\".self::class.' {}');
        }

        Relation::morphMap([$type => $class]);

        /** @var class-string<WorldModel> $class */
        return static::$defined[$type] = $class;
    }

    public function getTable(): string
    {
        return 'docs_'.Str::snake(Str::pluralStudly(class_basename($this)));
    }

    /** The model's table, in a database that does not have it yet. */
    public static function migrate(): void
    {
        $table = (new static)->getTable();

        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('label');
                $table->string('url')->nullable();
                $table->json('data')->nullable();
                $table->json('body')->nullable();
                $table->json('media')->nullable();
                $table->timestamps();
            });
        }
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(label: $this->label, data: $this->data ?? [], body: $this->body);
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        $model = $context->model();

        if (! $model instanceof self) {
            return null;
        }

        $image = fn (string $slot): ?FeedImage => isset($model->media[$slot]) ? FeedImage::make(
            src: $model->media[$slot]['src'],
            mediaType: $model->media[$slot]['mediaType'] ?? null,
            width: $model->media[$slot]['width'] ?? null,
            height: $model->media[$slot]['height'] ?? null,
            alt: $model->media[$slot]['alt'] ?? null,
        ) : null;

        return FeedMedia::make($model->url, icon: $image('icon'), preview: $image('preview'), image: $image('image'));
    }
}
