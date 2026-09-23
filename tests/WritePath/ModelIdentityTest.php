<?php

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Body\Excerpt;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\Feedables;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Workbench\App\Models\Customer;

/** A Feedable with no feed code at all. */
class Plate extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'dishes';

    protected $guarded = [];
}

/** No name, no title, no noun: the class-name rung. */
class MenuItem extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'dishes';

    protected $guarded = [];
}

/** A title attribute, and a per-model override of the ladder. */
class Recipe extends Model implements Feedable
{
    use InteractsWithFeed {
        guessFeedLabel as ladderLabel;
    }

    public static bool $overridesGuess = false;

    protected $table = 'dishes';

    protected $guarded = [];

    public function guessFeedLabel(): string
    {
        return static::$overridesGuess ? "Recipe {$this->getKey()}" : $this->ladderLabel();
    }

    protected function title(): Attribute
    {
        return Attribute::get(fn () => $this->notes);
    }
}

/** Both halves written by hand, beside a describeFeed() and a feedMediaUsing(). */
class HandWrittenDish extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'dishes';

    protected $guarded = [];

    public function describeFeed(): void
    {
        $this->feedEntity()->label('from describeFeed()');
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make()->label('by hand');
    }

    protected static function booted(): void
    {
        static::feedMediaUsing(fn () => '/from-the-closure');
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return FeedMedia::make()->url('/by-hand');
    }
}

/** Calls feedEntity() more than once while describing. */
class TwoStepDish extends Model implements Feedable
{
    use InteractsWithFeed;

    protected $table = 'dishes';

    protected $guarded = [];

    public function describeFeed(): void
    {
        $this->feedEntity()->label('Two steps');
        $this->feedEntity()->data('first', 1);
        $this->feedEntity()->data('second', 2);
    }
}

beforeEach(function () {
    Relation::morphMap([
        'plate' => Plate::class,
        'menu_item' => MenuItem::class,
        'recipe' => Recipe::class,
        'hand_written_dish' => HandWrittenDish::class,
        'two_step_dish' => TwoStepDish::class,
    ]);

    Recipe::$overridesGuess = false;
});

function snapshotOf(Model $model): Snapshot
{
    return Snapshot::query()
        ->where('model_type', $model->getMorphClass())
        ->where('model_id', $model->getKey())
        ->sole();
}

describe('a model with no feed code', function () {
    it('snapshots on save under its name, and is not a link', function () {
        $plate = Plate::create(['name' => 'Carrot soup']);

        Storyfeed::activity('plate', $plate)->publish();

        $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];

        expect(snapshotOf($plate)->label)->toBe('Carrot soup')
            ->and($object['label'])->toBe('Carrot soup')
            ->and($object['url'])->toBeNull()
            ->and($object['media'])->toBeNull();
    });

    it('is saved without a label written anywhere', function () {
        $plate = Plate::create();

        expect(snapshotOf($plate)->label)->toBe("Plate #{$plate->id}");
    });
});

describe('describeFeed()', function () {
    it('writes the entity through $this->feedEntity()', function () {
        $dish = Dish::create(['number' => '42', 'course' => 'main', 'notes' => 'Slow-roasted.']);

        $entity = $dish->toFeed();

        expect($entity->label)->toBe('Dish #42')
            ->and($entity->data)->toBe(['course' => 'main'])
            ->and($entity->body)->toBe([Excerpt::make()->text('Slow-roasted.')->toPayload()]);
    });

    it('hands back the same entity on every feedEntity() call within one toFeed()', function () {
        $entity = (new TwoStepDish)->forceFill(['id' => 1])->toFeed();

        expect($entity->label)->toBe('Two steps')
            ->and($entity->data)->toBe(['first' => 1, 'second' => 2]);
    });

    it('starts each toFeed() from an empty entity', function () {
        $dish = (new TwoStepDish)->forceFill(['id' => 1]);

        $dish->toFeed();

        expect($dish->toFeed()->data)->toBe(['first' => 1, 'second' => 2]);
    });

    it('guesses the label when describeFeed() sets none', function () {
        $dish = Dish::create(['name' => 'Soup of the day']);

        expect($dish->toFeed()->label)->toBe('Soup of the day');
    });
});

describe('feedMediaUsing()', function () {
    it('resolves before any instance of the model exists', function () {
        Model::clearBootedModels();
        app()->forgetInstance(Feedables::class);

        $media = Dish::feedMedia(new FeedContext(type: 'dish', key: 5));

        expect($media?->href())->toBe('/dishes/5')
            ->and($media?->icon?->src)->toBe('/icons/dish.png');
    });

    it('replaces rather than stacks when the model boots again', function () {
        new Dish;
        $first = app(Feedables::class)->mediaResolver(Dish::class);

        Model::clearBootedModels();
        new Dish;

        expect(app(Feedables::class)->mediaResolver(Dish::class))->not->toBe($first)
            ->and(Dish::feedMedia(new FeedContext(type: 'dish', key: 5))?->href())->toBe('/dishes/5');
    });

    it('takes a returned string as the url', function () {
        $media = Feedables::resolveMedia(fn () => '/somewhere', new FeedContext(type: 'dish'));

        expect($media?->href())->toBe('/somewhere');
    });

    it('reaches the payload', function () {
        $dish = Dish::create(['number' => '7']);

        Storyfeed::activity('plate', $dish)->publish();

        expect(Storyfeed::feed()->get()->toArray()['items'][0]['object']['url'])->toBe("/dishes/{$dish->id}");
    });

    it('leaves a model that registered none unlinkable', function () {
        expect(Plate::feedMedia(new FeedContext(type: 'plate', key: 1)))->toBeNull();
    });
});

it('lets a hand-written toFeed() and feedMedia() win over the trait', function () {
    $dish = HandWrittenDish::create();

    expect(snapshotOf($dish)->label)->toBe('by hand')
        ->and(HandWrittenDish::feedMedia(new FeedContext(type: 'hand_written_dish', key: 1))?->href())->toBe('/by-hand');
});

describe('the default label', function () {
    it('reads a name attribute first', function () {
        expect((new Plate)->forceFill(['id' => 42, 'name' => 'Carrot soup'])->toFeed()->label)->toBe('Carrot soup');
    });

    it('reads a title, including an accessor', function () {
        expect((new Recipe)->forceFill(['id' => 42, 'notes' => 'Borscht'])->toFeed()->label)->toBe('Borscht');
    });

    it('skips a blank name', function () {
        expect((new Plate)->forceFill(['id' => 42, 'name' => '  '])->toFeed()->label)->toBe('Plate #42');
    });

    it('uses the registered noun and the key', function () {
        Storyfeed::nouns(['plate' => 'dish|dishes']);

        expect((new Plate)->forceFill(['id' => 42])->toFeed()->label)->toBe('Dish #42');
    });

    it('falls back to the class name as words and the key', function () {
        expect((new MenuItem)->forceFill(['id' => 42])->toFeed()->label)->toBe('Menu Item #42');
    });

    it('does not trip preventAccessingMissingAttributes', function () {
        Model::preventAccessingMissingAttributes();

        try {
            $label = (new MenuItem)->forceFill(['id' => 42])->toFeed()->label;
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        expect($label)->toBe('Menu Item #42');
    });

    it('asks the app-wide guesser first', function () {
        Storyfeed::guessFeedLabelsUsing(fn (Model $model) => $model->getMorphClass().' '.$model->getKey());

        expect((new Plate)->forceFill(['id' => 42, 'name' => 'Carrot soup'])->toFeed()->label)->toBe('plate 42');
    });

    it('falls through to the ladder when the app-wide guesser returns null', function () {
        Storyfeed::guessFeedLabelsUsing(fn (Model $model) => $model instanceof Plate ? null : 'guessed');

        expect((new Plate)->forceFill(['id' => 42])->toFeed()->label)->toBe('Plate #42')
            ->and((new MenuItem)->forceFill(['id' => 42])->toFeed()->label)->toBe('guessed');
    });

    it('lets a model override guessFeedLabel(), ahead of the app-wide guesser', function () {
        Recipe::$overridesGuess = true;
        Storyfeed::guessFeedLabelsUsing(fn () => 'app-wide');

        expect((new Recipe)->forceFill(['id' => 42])->toFeed()->label)->toBe('Recipe 42');
    });
});

describe('Storyfeed::feedable()', function () {
    beforeEach(function () {
        Storyfeed::feedable(Photo::class)
            ->toFeedUsing(fn (Photo $photo, FeedEntity $entity) => $entity->label($photo->file_name)->data('kind', 'photo'))
            ->feedMediaUsing(fn (FeedContext $context, FeedMedia $media) => $media->url("/photos/{$context->key()}"));
    });

    it('snapshots a registered model on save', function () {
        $photo = Photo::create(['file_name' => 'soup.jpg']);

        expect(snapshotOf($photo)->label)->toBe('soup.jpg')
            ->and(snapshotOf($photo)->data)->toBe(['kind' => 'photo']);

        $photo->update(['file_name' => 'soup-final.jpg']);

        expect(snapshotOf($photo)->label)->toBe('soup-final.jpg');
    });

    it('treats a registered model as Feedable when publishing and reading', function () {
        $photo = Photo::create(['file_name' => 'soup.jpg']);
        $customer = Customer::create(['name' => 'Acme Co.']);

        Storyfeed::activity('upload', $photo)->for($customer)->publish();

        $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];

        expect($object['label'])->toBe('soup.jpg')
            ->and($object['url'])->toBe("/photos/{$photo->id}");
    });

    it('hears the registered model being deleted', function () {
        $photo = Photo::create(['file_name' => 'soup.jpg']);

        $activity = Storyfeed::activity('upload', $photo)->publish();
        Storyfeed::activity()->verb('ping')->publish();

        $photo->delete();

        expect(Activity::query()->count())->toBe(2)
            ->and($activity->fresh()->object_type)->toBe('storyfeed.tombstone')
            ->and(FeedTombstone::sole()->restorable)->toBeTrue();
    });

    it('hears the registered model being force-deleted', function () {
        $photo = Photo::create(['file_name' => 'soup.jpg']);

        Storyfeed::activity('upload', $photo)->publish();

        $photo->forceDelete();

        expect(Activity::query()->count())->toBe(1)
            ->and(FeedTombstone::sole()->restorable)->toBeFalse();
    });

    it('hears the registered model being restored', function () {
        $photo = Photo::create(['file_name' => 'soup.jpg']);

        $activity = Storyfeed::activity('upload', $photo)->publish();

        $photo->delete();
        $photo->restore();

        expect($activity->fresh()->object_type)->toBe('photo')
            ->and($activity->fresh()->cachedObject->label)->toBe('soup.jpg')
            ->and(FeedTombstone::query()->count())->toBe(0);
    });

    it('guesses the label when toFeedUsing() sets none', function () {
        Storyfeed::feedable(Photo::class)->toFeedUsing(fn (Photo $photo, FeedEntity $entity) => null);

        $photo = Photo::create(['file_name' => 'soup.jpg']);

        expect(snapshotOf($photo)->label)->toBe("Photo #{$photo->id}");
    });

    it('returns the same registration for the same class', function () {
        expect(Storyfeed::feedable(Photo::class))->toBe(Storyfeed::feedable(Photo::class));
    });

    it('refuses a class that already implements Feedable', function () {
        Storyfeed::feedable(Dish::class);
    })->throws(InvalidArgumentException::class, 'already implements Feedable');

    it('keeps its registrations when Storyfeed is faked', function () {
        Storyfeed::fake();

        $photo = Photo::create(['file_name' => 'soup.jpg']);

        expect(app(Feedables::class)->isFeedable($photo))->toBeTrue()
            ->and(app(Feedables::class)->toFeed($photo)->label)->toBe('soup.jpg');
    });
});
