<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Storyfeed\ActivityContext;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedHeadline;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('uses Laravel data helper semantics for every inherited helper', function (string $method, array $arguments) {
    $data = [
        'rush' => 'yes', 'count' => '12', 'price' => '3.25', 'name' => ' Dana ',
        'date' => '2026-09-26', 'empty' => ' ', 'null' => null,
        'nested' => ['name' => 'Sam'], 'items' => ['a', 'b'],
        'verb' => ActivityVerb::cases()[0]->value,
        'verbs' => [ActivityVerb::cases()[0]->value, 'invalid'],
        'duration' => '2 hours',
    ];
    $context = new ActivityContext(data: $data);
    $fluent = new Fluent($data);

    expect($context->$method(...$arguments))->toEqual($fluent->$method(...$arguments));
})->with([
    ['all', []], ['all', [['nested.name', 'absent']]], ['all', ['name', 'rush']],
    ['get', ['name']], ['get', ['nested.name']], ['get', ['absent', 'fallback']], ['get', ['null', 'fallback']],
    ['boolean', ['rush']], ['boolean', ['absent', true]],
    ['string', ['nested.name']], ['str', ['absent', 'fallback']],
    ['integer', ['count']], ['integer', ['absent', 5]], ['float', ['price']],
    ['date', ['date', '!Y-m-d', 'UTC']], ['date', ['absent']],
    ['enum', ['verb', ActivityVerb::class]], ['enum', ['absent', ActivityVerb::class]],
    ['enums', ['verbs', ActivityVerb::class]], ['array', ['items']], ['array', [['name', 'nested.name']]],
    ['collect', []], ['collect', ['items']], ['collect', [['name']]],
    ['exists', ['null']], ['has', [['null', 'nested.name']]], ['hasAny', [['absent', 'rush']]],
    ['filled', ['empty']], ['filled', ['rush']], ['isNotFilled', ['null']],
    ['anyFilled', [['empty', 'name']]], ['missing', ['absent']],
    ['only', [['nested.name', 'absent']]], ['except', [['nested.name', 'rush']]],
]);

it('uses Laravel conditional helpers and preserves the context for chaining', function () {
    $context = new ActivityContext(data: ['rush' => true, 'empty' => '']);

    expect($context->whenHas('rush', fn ($value) => $value ? 'yes' : 'no'))->toBe('yes')
        ->and($context->whenFilled('empty', fn () => 'wrong', fn () => 'empty'))->toBe('empty')
        ->and($context->whenMissing('absent', fn ($value) => $value === null ? 'missing' : 'wrong'))->toBe('missing')
        ->and($context->whenHas('absent', fn () => 'wrong'))->toBe($context)
        ->and($context->whenFilled('rush', fn () => null))->toBe($context);
});

it('inherits additional helpers from the installed Laravel version', function () {
    $context = new ActivityContext(data: ['count' => 12, 'duration' => '2 hours', 'verb' => ActivityVerb::cases()[0]->value]);
    $fluent = new Fluent($context->all());
    foreach (['clamp' => ['count', 0, 5], 'interval' => ['duration'], 'whenEnum' => ['verb', ActivityVerb::class, fn ($case) => $case->value]] as $method => $arguments) {
        if (method_exists($fluent, $method)) {
            expect($context->$method(...$arguments))->toEqual($fluent->$method(...$arguments));
        }
    }
});

it('rejects unknown methods and does not expose the model', function () {
    $context = new ActivityContext;
    expect(fn () => $context->unknown())->toThrow(Error::class)
        ->and(fn () => $context->model())->toThrow(Error::class)
        ->and((new ReflectionClass($context))->isReadOnly())->toBeTrue();
});

it('passes every recorded role and immutable publication time to headline closures', function (string $role) {
    $seen = null;
    Story::verb('inspect')->headline(function (ActivityContext $activity) use (&$seen) {
        $seen = $activity;

        return $activity->boolean('rush') ? 'Rushed' : 'Normal';
    });
    $user = User::create(['name' => 'Dana', 'email' => 'dana@example.com']);
    $at = CarbonImmutable::parse('2026-09-26 10:00:00', 'UTC');
    $recorded = Storyfeed::activity('inspect')->$role($user)->data(['rush' => true])->publishedAt($at)->publish();
    $node = app(NodePresenter::class)->forFeed('kitchen')->activityNode($recorded->fresh(ActivityRoles::cachedRelations()));

    expect($node['headline'])->toBe('Rushed')
        ->and($seen->verb())->toBe('inspect')
        ->and($seen->publishedAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($seen->publishedAt()->equalTo($at))->toBeTrue()
        ->and($seen->$role())->toBeInstanceOf(FeedContext::class)
        ->and($seen->$role()->type())->toBe($user->getMorphClass())
        ->and((string) $seen->$role()->key())->toBe((string) $user->getKey())
        ->and($seen->$role()->label())->toBe('Dana')
        ->and($seen->$role()->feed())->toBe('kitchen');
    foreach (array_diff(ActivityRoles::PAYLOAD, [$role]) as $empty) {
        expect($seen->$empty())->toBeNull();
    }
    $seen->publishedAt()->addDay();
    expect($seen->publishedAt()->equalTo($at))->toBeTrue();
})->with(ActivityRoles::PAYLOAD);

it('passes a context through every headline registration path', function (string $kind) {
    $headline = fn (ActivityContext $activity) => FeedHeadline::trans($activity->boolean('rush') ? 'Rushed' : 'Normal');
    match ($kind) {
        'headline' => Story::verb('inspect')->headline($headline),
        'anonymous' => Story::verb('inspect')->headline('Normal')->anonymousHeadline($headline),
        'missing' => Story::verb('inspect')->headline('Normal')->missingHeadline($headline),
        'grammar' => Storyfeed::grammar(['*.inspect' => $headline]),
        'actorless grammar' => Storyfeed::actorlessGrammar(['inspect' => $headline]),
    };
    $delivery = Delivery::create(['tracking_number' => 'CTX']);
    Storyfeed::anonymous()->verb('inspect', $delivery)->data(['rush' => true])->publish();
    $missing = str_contains($kind, 'missing');
    if ($missing) {
        $delivery->delete();
    }
    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($node[$missing ? 'missing_headline' : 'headline'])->toBe('Rushed');
})->with(['headline', 'anonymous', 'missing', 'grammar', 'actorless grammar']);

it('passes contexts to normal and anonymous Activity Streams headlines', function (bool $anonymous) {
    $seen = null;
    $headline = function (ActivityContext $activity) use (&$seen) {
        $seen = $activity;

        return $activity->boolean('rush') ? 'Rushed' : 'Normal';
    };
    $verb = Story::verb('inspect')->headline($headline);
    if ($anonymous) {
        $verb->headline('Wrong')->anonymousHeadline($headline);
    }
    $pending = Storyfeed::activity('inspect')->data(['rush' => true]);
    if (! $anonymous) {
        $pending->actor(User::create(['name' => 'Dana', 'email' => 'dana@example.com']));
    }
    expect(serialize_one($pending->publish())['summary'])->toBe('Rushed')
        ->and($seen)->toBeInstanceOf(ActivityContext::class)
        ->and($seen->actor()?->feed())->toBeNull();
})->with([false, true]);

it('preserves recorded identity when a role snapshot is absent', function () {
    $seen = null;
    Storyfeed::grammar(['*.inspect' => function (object $activity) use (&$seen) {
        $seen = $activity;

        return 'Inspected';
    }]);
    $activity = new Activity(['verb' => 'inspect', 'object_type' => 'unknown', 'object_id' => 42]);
    app(NodePresenter::class)->activityNode($activity);
    expect($seen)->toBeInstanceOf(ActivityContext::class)
        ->and($seen->object()->type())->toBe('unknown')
        ->and($seen->object()->key())->toBe(42)
        ->and($seen->object()->label())->toBeNull()
        ->and($seen->object()->data())->toBe([])
        ->and($seen->publishedAt())->toBeNull()
        ->and($seen->all())->toBe([]);
});

it('builds role contexts without queries and shares page hydration between headlines', function () {
    $contexts = [];
    Story::verb('inspect')->headline(function (ActivityContext $activity) use (&$contexts) {
        $contexts[] = $activity;

        return $activity->actor()->label();
    });
    $records = collect(['Dana', 'Sam'])->map(function (string $name) {
        $actor = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        return Storyfeed::activity('inspect')->actor($actor)->publish()->fresh(ActivityRoles::cachedRelations());
    });
    $records[0]->cachedActor->meta = ['route_key' => 'dana'];
    $slices = $records->map(fn (Activity $activity) => GroupSlice::solo($activity));
    $presenter = app(NodePresenter::class)->forPage($slices);
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        foreach ($slices as $slice) {
            $presenter->node($slice);
        }
        expect($connection->getQueryLog())->toBe([])
            ->and($contexts[0]->actor()->routeKey())->toBe('dana')
            ->and($contexts[0]->actor()->data('name'))->toBe('Dana')
            ->and($contexts[0]->actor()->model()->name)->toBe('Dana')
            ->and($contexts[1]->actor()->model()->name)->toBe('Sam')
            ->and($connection->getQueryLog())->toHaveCount(1);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
});
