<?php

use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    config()->set('storyfeed.grouping.batch.enabled', false);
    $this->people = collect(['Aiko', 'Jasper', 'Tomás'])->map(fn ($name, $i) => User::create(['name' => $name, 'email' => "target{$i}@example.com"]));
    $this->document = Customer::create(['name' => 'hero-mobile-print-ready.fig']);
    $this->project = Customer::create(['name' => 'Spring Campaign']);
    $this->start = now()->subDay();
});

it('renders Newsroom comments with the document as the pinned target and comments as children', function (bool $shorthand) {
    Story::verb('comment')->headline(':actor commented on :target')->grouped(
        $shorthand
            ? fn (GroupBuilder $group) => $group->actorsOnTarget(':actors commented on :target')
            : Group::byActorsOnTarget()->headline(':actors commented on :target'),
    );
    foreach ($this->people->reverse()->values() as $i => $actor) {
        $comment = $i === 2 ? Delivery::create(['tracking_number' => 'Comment 2']) : Customer::create(['name' => "Comment {$i}"]);
        Storyfeed::activity()->actor($actor)->verb('comment', $comment)
            ->target($this->document)->context($this->project)->publishedAt($this->start->copy()->addMinutes($i))->publish();
    }
    $feed = Storyfeed::feed()->live()->get();
    $node = $feed->items()[0];
    expect($feed->items())->toHaveCount(1)
        ->and($node['axis'])->toBe('actors_target')
        ->and($node['count'])->toBe(3)
        ->and($node['target']['label'])->toBe('hero-mobile-print-ready.fig')
        ->and($node['object'])->toBeNull()
        ->and($node['actor'])->toBeNull()
        ->and($node['context']['label'])->toBe('Spring Campaign')
        ->and($node['sample']['objects'])->toHaveCount(3)
        ->and($node['distinct']['objects'])->toBe(3)
        ->and($node['children'])->toHaveCount(3)
        ->and(collect($node['children'])->pluck('target.key')->unique())->toHaveCount(1)
        ->and($feed->collect()->first()->headline()->toString())->toBe('Aiko, Jasper and Tomás commented on hero-mobile-print-ready.fig')
        ->and(Grouping::query()->where('winner', true)->count())->toBe(Activity::query()->count());

    // Simulate L2 history: it had no target-social memberships. Replay adds
    // them and leaves both memberships and winner stamps stable on a rerun.
    Grouping::query()->where('bucket', 'actors_target')->delete();
    $this->artisan('storyfeed:curate --rebuild-bursts')->assertSuccessful();
    $snapshot = fn () => Grouping::query()->orderBy('activity_id')->orderBy('bucket')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();
    $before = $snapshot();
    expect(Storyfeed::feed()->live()->get()->items()[0]['axis'])->toBe('actors_target');
    $this->artisan('storyfeed:curate --rebuild-bursts')->assertSuccessful();
    expect($snapshot())->toBe($before);
})->with([false, true]);

it('prefers an eligible object social row and assigns each remaining activity only once', function () {
    foreach ($this->people as $i => $actor) {
        Storyfeed::activity()->actor($actor)->verb('comment', $this->project)->target($this->document)->publish();
        Storyfeed::activity()->actor($actor)->verb('comment', Customer::create(['name' => "Other comment {$i}"]))->target($this->document)->publish();
    }
    $items = collect(Storyfeed::feed()->live()->get()->items());
    expect($items)->toHaveCount(2)
        ->and($items->firstWhere('axis', 'actors')['count'])->toBe(3)
        ->and($items->firstWhere('axis', 'actors_target')['count'])->toBe(3)
        ->and(Grouping::query()->where('winner', true)->count())->toBe(6);
});

it('uses the social threshold before falling back to the person row', function () {
    config()->set('storyfeed.grouping.policy.min_actors', 4);
    foreach ([$this->people[0], $this->people[0], $this->people[1], $this->people[2]] as $i => $actor) {
        Storyfeed::activity()->actor($actor)->verb('comment', Customer::create(['name' => "Comment {$i}"]))->target($this->document)->publish();
    }
    expect(collect(Storyfeed::feed()->live()->get()->items())->pluck('axis'))->not->toContain('actors_target');
    Storyfeed::activity()->actor(User::create(['name' => 'Priya', 'email' => 'priya@example.com']))
        ->verb('comment', Customer::create(['name' => 'Last comment']))->target($this->document)->publish();
    $items = Storyfeed::feed()->live()->get()->items();
    expect($items)->toHaveCount(1)->and($items[0]['axis'])->toBe('actors_target')->and($items[0]['count'])->toBe(5);
});

it('keeps target social bursts separate by verb, target, context, quiet gap and ceiling', function () {
    config()->set('storyfeed.grouping.bursts', ['within' => '3 minutes', 'ceiling' => '4 minutes']);
    $publish = fn ($minute, $verb = 'comment', $target = null, $context = null) => Storyfeed::activity()->actor($this->people[$minute % 3])
        ->verb($verb, Customer::create(['name' => "Comment {$minute}"]))->target($target ?? $this->document)->context($context ?? $this->project)
        ->publishedAt($this->start->copy()->addMinutes($minute))->publish();
    $first = $publish(0);
    $second = $publish(2);
    $ceiling = $publish(4);
    $gap = $publish(7);
    $hash = fn ($a) => Grouping::query()->where('activity_id', $a->id)->where('bucket', 'actors_target')->value('hash');
    expect($hash($first))->toBe($hash($second))
        ->and($hash($ceiling))->not->toBe($hash($first))
        ->and($hash($gap))->not->toBe($hash($ceiling))
        ->and($hash($publish(8, 'reply')))->not->toBe($hash($gap))
        ->and($hash($publish(8, target: $this->project)))->not->toBe($hash($gap))
        ->and($hash($publish(8, context: $this->document)))->not->toBe($hash($gap));
});

it('omits and removes the target candidate when no target is present', function () {
    $activity = Storyfeed::activity()->actor($this->people[0])->verb('comment', $this->project)->publish();
    expect(Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'actors_target')->exists())->toBeFalse();
    $targeted = Storyfeed::activity()->actor($this->people[0])->verb('comment', $this->project)->target($this->document)->publish();
    $targeted->forceFill(['target_type' => null, 'target_id' => null])->save();
    (new WriteGroupings)->many([$targeted]);
    expect(Grouping::query()->where('activity_id', $targeted->id)->where('bucket', 'actors_target')->exists())->toBeFalse();
});

it('rejects a singular object token on the target social axis', function () {
    Story::verb('comment')->grouped(Group::byActorsOnTarget()->headline(':actors commented on :object'));
    expect(fn () => Storyfeed::compileStories())->toThrow(StoryMisconfigured::class, 'does not pin it');
});
