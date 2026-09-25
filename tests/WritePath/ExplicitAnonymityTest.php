<?php

use Storyfeed\Concerns\AsFeedVerb;
use Storyfeed\Facades\Story as StoryFacade;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('persists explicit anonymity without consulting any resolver', function (string $method, string $resolver) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->actingAs($user);
    config(['storyfeed.parties.fallback' => 'System']);
    $fail = fn () => throw new RuntimeException('Actor resolver must not run');
    if ($resolver === 'runtime') {
        Storyfeed::resolveActorUsing($fail);
    } else {
        app()->bind('anonymity.resolver', fn () => $fail);
        config(['storyfeed.actor_resolver' => 'anonymity.resolver']);
    }
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed anonymously']);
    $pending = Storyfeed::activity('confirm');
    $method === 'anonymously' ? $pending->anonymously() : $pending->{$method}(null);
    $activity = $pending->publish()->fresh();
    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($activity->actor_type)->toBeNull()
        ->and($activity->actor_id)->toBeNull()
        ->and($activity->cached_actor_id)->toBeNull()
        ->and($item['actor'])->toBeNull()
        ->and($item['headline_template'])->toBe('Confirmed anonymously');
})->with(['by', 'actor', 'anonymously'])->with(['runtime', 'configured']);

it('lets the last actor request win without retaining a stale snapshot', function (string $method, bool $anonymousLast) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    Storyfeed::resolveActorUsing(fn () => throw new RuntimeException('Unexpected resolver'));
    $pending = Storyfeed::activity('ping');
    if ($anonymousLast) {
        $pending->by($user);
        $method === 'anonymously' ? $pending->anonymously() : $pending->{$method}(null);
    } else {
        $method === 'anonymously' ? $pending->anonymously() : $pending->{$method}(null);
        $pending->by($user);
    }
    $activity = $pending->publish()->fresh();
    expect($activity->actor_type)->toBe($anonymousLast ? null : 'user')
        ->and($activity->actor_id)->toEqual($anonymousLast ? null : $user->id)
        ->and($activity->cachedActor?->label)->toBe($anonymousLast ? null : 'Sally');
})->with(['by', 'actor', 'anonymously'])->with([true, false]);

it('keeps omitted actors resolving across builder and record entry points', function (string $entry) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $calls = 0;
    Storyfeed::resolveActorUsing(function () use ($user, &$calls) {
        $calls++;

        return $user;
    });
    $activity = match ($entry) {
        'builder' => Storyfeed::activity('ping')->publish(),
        'record' => Storyfeed::record('ping'),
        'story' => publishAnonymityStory(new AnonymityTestStory),
        'record null' => Storyfeed::record('ping', actor: null),
        'enum' => AnonymityTestVerb::Ping->record(),
        'model' => Activity::create(['verb' => 'ping']),
    };
    expect($activity->fresh()->actor_id)->toEqual($user->id)
        ->and($calls)->toBe(1);
})->with(['builder', 'record', 'story', 'record null', 'enum', 'model']);

it('carries anonymity into composite members and overrides ambient actors', function () {
    $objects = collect([Delivery::create(['tracking_number' => 'A']), Delivery::create(['tracking_number' => 'B'])]);
    Storyfeed::as('System', fn () => Storyfeed::activity('confirm')->objects($objects)->anonymously()->publish());
    expect(Activity::count())->toBe(3);
    foreach (Activity::all() as $activity) {
        expect($activity->actor_type)->toBeNull()->and($activity->actor_id)->toBeNull()
            ->and($activity->cached_actor_id)->toBeNull();
    }
});

it('leaves null associations for other roles as no-ops', function () {
    $delivery = Delivery::create(['tracking_number' => 'A']);
    $activity = Storyfeed::activity('confirm', $delivery)->target($delivery)->context($delivery)
        ->object(null)->target(null)->context(null)->anonymously()->publish()->fresh();
    expect($activity->object_id)->toEqual($delivery->id)
        ->and($activity->target_id)->toEqual($delivery->id)
        ->and($activity->context_id)->toEqual($delivery->id);
});

class AnonymityTestStory extends Story
{
    public string|array|null $objectType = '*';

    public string|\Storyfeed\Contracts\FeedVerb|BackedEnum|null $verb = 'ping';

    public function __construct(public bool $anonymous = false, public ?User $by = null) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity()
            ->when($this->anonymous, fn (PendingActivity $pending) => $pending->anonymously())
            ->when($this->by, fn (PendingActivity $pending, User $user) => $pending->by($user));
    }

    public function headline(): string
    {
        return ':actor pinged';
    }
}

it('starts anonymous chains on every authoring surface and lets a later actor win', function (string $surface, bool $named) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    Storyfeed::resolveActorUsing(fn () => throw new RuntimeException('Unexpected resolver'));
    // A message's chain is its own: the later actor is given to it.
    if ($surface === 'story') {
        $activity = publishAnonymityStory(new AnonymityTestStory(anonymous: true, by: $named ? $user : null))->fresh();
    } else {
        $pending = match ($surface) {
            'facade' => Storyfeed::anonymous()->action('ping'),
            'enum' => AnonymityTestVerb::Ping->anonymous(),
        };
        if ($named) {
            $pending->by($user);
        }
        $activity = $pending->publish()->fresh();
    }
    expect($activity->verb)->toBe('ping')
        ->and($activity->actor_id)->toEqual($named ? $user->id : null);
})->with(['facade', 'story', 'enum'])->with([true, false]);

function publishAnonymityStory(AnonymityTestStory $story): Activity
{
    StoryFacade::verb('ping', AnonymityTestStory::class);

    return Storyfeed::publish($story) ?? throw new LogicException('The message published nothing.');
}

enum AnonymityTestVerb: string
{
    use AsFeedVerb;

    case Ping = 'ping';
}
