<?php

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Body\Prose;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;

class ReadPathPresenterProbe extends NodePresenter
{
    public function roles(Activity $activity): array
    {
        return $this->roleFields($activity);
    }

    public function participant(Snapshot $snapshot): array
    {
        return $this->entity('resolver-probe', '1', $snapshot);
    }

    public function stored(Snapshot $snapshot): array
    {
        return ['label' => $this->snapshotPlain($snapshot, 'label'), 'data' => $this->snapshotJson($snapshot, 'data')];
    }
}

class ReadPathResolverProbe extends Delivery
{
    public static ?Closure $callback = null;

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return (self::$callback)($context);
    }
}

class ReadPathSnapshotCast implements CastsAttributes
{
    public static int $version = 1;

    public function get($model, string $key, $value, array $attributes): array
    {
        return ['version' => self::$version];
    }

    public function set($model, string $key, $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

it('reads current role attributes without changing inbound key casts', function () {
    $presenter = new ReadPathPresenterProbe(app(StoryfeedManager::class));
    $activity = new Activity;
    $activity->setRawAttributes(['actor_type' => 'user', 'actor_id' => '001', 'object_type' => null, 'object_id' => null]);
    expect($presenter->roles($activity)['actor_id'])->toBe($activity->actor_id)->toBe('001');
    $activity->actor_id = 42;
    expect($presenter->roles($activity)['actor_id'])->toBe($activity->actor_id)->toBe('42');
});

it('keeps custom role accessors and outbound casts fresh', function () {
    $presenter = new ReadPathPresenterProbe(app(StoryfeedManager::class));
    $activity = new class extends Activity
    {
        public string $label = 'first';

        protected function actorType(): Attribute
        {
            return Attribute::make(get: fn () => $this->label);
        }
    };
    $activity->setRawAttributes(['actor_type' => 'stored', 'actor_id' => '007']);
    $activity->mergeCasts(['actor_id' => 'integer']);
    expect($presenter->roles($activity)['actor_type'])->toBe('first')
        ->and($presenter->roles($activity)['actor_id'])->toBe(7);
    $activity->label = 'second';
    $activity->mergeCasts(['actor_id' => 'string']);
    expect($presenter->roles($activity)['actor_type'])->toBe('second')
        ->and($presenter->roles($activity)['actor_id'])->toBe('007');
});

it('preserves a custom model attribute reader', function () {
    $presenter = new ReadPathPresenterProbe(app(StoryfeedManager::class));
    $activity = new class extends Activity
    {
        public function getAttribute($key)
        {
            return $key === 'actor_type' ? 'custom-reader' : parent::getAttribute($key);
        }
    };
    $activity->setRawAttributes(['actor_type' => 'stored', 'actor_id' => '1']);
    expect($presenter->roles($activity)['actor_type'])->toBe('custom-reader');
});

it('refreshes decoded snapshot fields after an attribute changes on the same page', function () {
    $presenter = (new ReadPathPresenterProbe(app(StoryfeedManager::class)))->forPage(collect());
    $snapshot = new Snapshot(['label' => 'first', 'data' => ['version' => 1]]);
    expect($presenter->stored($snapshot)['label'])->toBe('first')
        ->and($presenter->stored($snapshot)['data'])->toBe(['version' => 1]);
    $snapshot->label = 'second';
    $snapshot->data = ['version' => 2];
    expect($presenter->stored($snapshot)['label'])->toBe('second')
        ->and($presenter->stored($snapshot)['data'])->toBe(['version' => 2]);
});

it('does not memoize a custom snapshot accessor', function () {
    $presenter = (new ReadPathPresenterProbe(app(StoryfeedManager::class)))->forPage(collect());
    $snapshot = new class extends Snapshot
    {
        public string $current = 'first';

        public function getLabelAttribute(): string
        {
            return $this->current;
        }
    };
    expect($presenter->stored($snapshot)['label'])->toBe('first');
    $snapshot->current = 'second';
    expect($presenter->stored($snapshot)['label'])->toBe('second');
});

it('keeps per-instance snapshot casts live', function () {
    $presenter = (new ReadPathPresenterProbe(app(StoryfeedManager::class)))->forPage(collect());
    $snapshot = new Snapshot(['label' => '42']);
    $snapshot->mergeCasts(['label' => 'integer']);
    expect($presenter->stored($snapshot)['label'])->toBe($snapshot->label)->toBe(42);
    $snapshot->mergeCasts(['label' => 'string']);
    expect($presenter->stored($snapshot)['label'])->toBe($snapshot->label)->toBe('42');
});

it('keeps custom JSON casts fresh when the stored bytes do not change', function () {
    $presenter = (new ReadPathPresenterProbe(app(StoryfeedManager::class)))->forPage(collect());
    $snapshot = new Snapshot(['data' => ['version' => 0]]);
    expect($presenter->stored($snapshot)['data'])->toBe(['version' => 0]);
    $snapshot->mergeCasts(['data' => ReadPathSnapshotCast::class]);
    ReadPathSnapshotCast::$version = 1;
    expect($presenter->stored($snapshot)['data'])->toBe(['version' => 1]);
    ReadPathSnapshotCast::$version = 2;
    expect($presenter->stored($snapshot)['data'])->toBe(['version' => 2]);
    $snapshot->mergeCasts(['data' => 'array']);
    expect($presenter->stored($snapshot)['data'])->toBe(['version' => 0]);
});

it('keeps live and deferred resolver reads fresh at their original field boundaries', function () {
    Relation::morphMap(['resolver-probe' => ReadPathResolverProbe::class]);
    $presenter = (new ReadPathPresenterProbe(app(StoryfeedManager::class)))->forPage(collect());
    $snapshot = new Snapshot(['label' => 'initial label', 'data' => ['version' => 0], 'body' => [Prose::make('initial body')->toPayload()], 'content' => 'initial content']);
    $mediaCalls = $bodyCalls = 0;
    ReadPathResolverProbe::$callback = function (FeedContext $context) use ($snapshot, &$mediaCalls, &$bodyCalls) {
        $mediaCalls++;
        $snapshot->data = ['version' => $mediaCalls];
        $snapshot->body = [Prose::make('after live')->toPayload()];

        return FeedMedia::make('/fresh/'.$mediaCalls, body: function () use ($snapshot, &$bodyCalls) {
            $bodyCalls++;
            $snapshot->label = 'after deferred';
            $snapshot->content = 'after deferred';
            $snapshot->body = [Prose::make('after deferred')->toPayload()];

            return Prose::make('minted '.$bodyCalls);
        });
    };
    try {
        $first = $presenter->participant($snapshot);
        expect($first['data'])->toBe(['version' => 0])
            ->and($first['label'])->toBe('initial label')
            ->and($first['content'])->toBe('after deferred')
            ->and($first['body'])->toBe([Prose::make('after live')->toPayload(), Prose::make('minted 1')->toPayload()]);
        $second = $presenter->participant($snapshot);
        expect($second['data'])->toBe(['version' => 1])
            ->and($second['link']['href'])->toBe('/fresh/2')
            ->and($second['body'])->toBe([Prose::make('after live')->toPayload(), Prose::make('minted 2')->toPayload()])
            ->and($mediaCalls)->toBe(2)->and($bodyCalls)->toBe(2);
    } finally {
        ReadPathResolverProbe::$callback = null;
    }
});
