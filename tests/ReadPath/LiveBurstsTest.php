<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    config()->set('storyfeed.grouping.batch.enabled', false);
    $this->person = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->doc = Delivery::create(['tracking_number' => 'tokens.pdf']);
    $this->start = now()->subDay();
});

function burstHash(Activity $activity, string $axis = 'repeat'): string
{
    return Grouping::query()->where('activity_id', $activity->id)->where('bucket', $axis)->value('hash');
}

it('slides on the quiet gap, crosses midnight and starts a new row at the exact gap', function () {
    $start = now()->subDay()->startOfDay()->addHours(23)->addMinutes(50);
    $rows = collect([0, 10, 20, 35, 36])->map(fn ($minute) => Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)
        ->publishedAt($start->copy()->addMinutes($minute))->publish());
    expect(burstHash($rows[0]))->toBe(burstHash($rows[2]))
        ->and(burstHash($rows[3]))->not->toBe(burstHash($rows[2]))
        ->and(burstHash($rows[3]))->toBe(burstHash($rows[4]));
    $items = Storyfeed::feed()->live()->get()->items();
    expect(array_column($items, 'count'))->toBe([2, 3]);
});

it('closes at the hard ceiling even with no quiet gap', function () {
    $rows = collect(range(0, 240, 10))->map(fn ($minute) => Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)
        ->publishedAt($this->start->copy()->addMinutes($minute))->publish());
    expect(burstHash($rows[0]))->toBe(burstHash($rows[23]))
        ->and(burstHash($rows[24]))->not->toBe(burstHash($rows[23]));
    $items = Storyfeed::feed()->live()->get()->items();
    expect($items)->toHaveCount(2)->and($items[0]['kind'])->toBe('activity')->and($items[1]['count'])->toBe(24);
});

it('keeps refreshes and late arrivals from merging or reopening closed bursts', function () {
    $publish = fn ($minute) => Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)
        ->publishedAt($this->start->copy()->addMinutes($minute))->publish();
    $first = $publish(0);
    $later = $publish(60);
    $before = burstHash($first);
    (new WriteGroupings)($first);
    (new WriteGroupings)->many([$first, $later]);
    $late = $publish(5);
    expect(burstHash($first))->toBe($before)
        ->and(burstHash($late))->not->toBe($before)
        ->and(burstHash($publish(61)))->toBe(burstHash($later));
});

it('gives social rows on one object precedence and never mixes verbs or places', function () {
    $project = Customer::create(['name' => 'Spring Campaign']);
    $other = Customer::create(['name' => 'Website Refresh']);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($this->person)->verb('comment', Delivery::create(['tracking_number' => "doc{$i}"]))->target($project)->context($project)->publish();
    }
    foreach ([$this->person, User::create(['name' => 'Priya', 'email' => 'priya@example.com']), User::create(['name' => 'Bob', 'email' => 'bob@example.com'])] as $actor) {
        Storyfeed::activity()->actor($actor)->verb('comment', $this->doc)->target($project)->context($project)->publish();
    }
    Storyfeed::activity()->actor($this->person)->verb('approve', $this->doc)->target($project)->context($project)->publish();
    Storyfeed::activity()->actor($this->person)->verb('comment', $this->doc)->target($other)->context($other)->publish();
    $items = Storyfeed::feed()->live()->get()->items();
    $social = collect($items)->firstWhere('axis', 'actors');
    expect($social['count'])->toBe(3)->and($social['distinct']['objects'])->toBe(1);
    foreach ($items as $item) {
        if ($item['kind'] === 'group') {
            expect(collect($item['children'])->pluck('verb')->unique())->toHaveCount(1)
                ->and(collect($item['children'])->pluck('context.key')->unique())->toHaveCount(1)
                ->and($item)->not->toHaveKeys(['period', 'phrases', 'phrases_truncated']);
        }
    }
    expect(Grouping::query()->where('winner', true)->count())->toBe(Activity::query()->count());
});

it('groups different objects acted on by different people on their shared target', function () {
    $project = Customer::create(['name' => 'Spring Campaign']);
    foreach (range(1, 3) as $i) {
        $actor = User::create(['name' => "Person {$i}", 'email' => "person{$i}@example.com"]);
        Storyfeed::activity()->actor($actor)->verb('comment', Delivery::create(['tracking_number' => "doc{$i}"]))->target($project)->publish();
    }
    expect(Storyfeed::feed()->live()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->live()->get()->items()[0]['axis'])->toBe('actors_target')
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0);
});

it('configures quiet gaps and ceilings globally and per verb on the type ladder', function () {
    config()->set('storyfeed.grouping.bursts', ['within' => '2 minutes', 'ceiling' => '10 minutes']);
    Story::verb('revise')->bursts(within: '5 minutes', ceiling: '20 minutes');
    Story::for(Delivery::class)->verb('revise')->bursts(within: '3 minutes', ceiling: '8 minutes');
    expect(Storyfeed::burstWindow('delivery', 'revise'))->toBe([180, 480])
        ->and(Storyfeed::burstWindow('customer', 'revise'))->toBe([300, 1200])
        ->and(Storyfeed::burstWindow('delivery', 'comment'))->toBe([120, 600]);
    $first = Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)->publishedAt($this->start)->publish();
    $second = Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)->publishedAt($this->start->copy()->addMinutes(3))->publish();
    expect(burstHash($first))->not->toBe(burstHash($second));
});

it('rejects invalid burst windows', function () {
    config()->set('storyfeed.grouping.bursts.within', '0 minutes');
    expect(fn () => Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)->publish())->toThrow(InvalidArgumentException::class, 'Live burst windows');
    expect(Activity::query()->count())->toBe(0);
    expect(fn () => Story::verb('bad')->bursts(within: '-1 minute'))->toThrow(InvalidArgumentException::class, 'positive interval');
});

it('registers the migration and creates it idempotently on configured tables', function () {
    $migration = include __DIR__.'/../../database/migrations/create_feed_grouping_bursts_table.php.stub';
    $migration->up();
    expect(Schema::hasTable('feed_grouping_bursts'))->toBeTrue();
    $migration->down();
    $migration->down();
    config()->set('storyfeed.tables.grouping_bursts', 'studio_bursts');
    $migration->up();
    $migration->up();
    Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)->publish();
    expect(DB::table('studio_bursts')->count())->toBe(4);
});

it('rebuilds historical calendar rows chronologically and idempotently, preserving composites', function () {
    foreach ([60, 0, 10, 70] as $minute) {
        Storyfeed::activity()->actor($this->person)->verb('revise', $this->doc)->publishedAt($this->start->copy()->addMinutes($minute))->publish();
    }
    $parent = Storyfeed::activity()->actor($this->person)->verb('upload')->objects([
        Delivery::create(['tracking_number' => 'bundle1']), Delivery::create(['tracking_number' => 'bundle2']),
    ])->publish();
    $composites = Grouping::query()->where('bucket', 'composite')->orderBy('id')->get(['activity_id', 'hash', 'winner'])->toArray();
    Grouping::query()->whereIn('bucket', ['actors', 'targets', 'object', 'repeat'])->update(['hash' => 'legacy:2026-10-07']);
    Grouping::query()->create(['activity_id' => Activity::query()->first()->id, 'bucket' => 'summary.day', 'hash' => 'legacy']);
    $this->artisan('storyfeed:curate --rebuild-bursts')->assertSuccessful();
    $snapshot = fn () => Grouping::query()->orderBy('activity_id')->orderBy('bucket')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();
    $before = $snapshot();
    expect(Grouping::query()->where('bucket', 'like', 'summary.%')->count())->toBe(0)
        ->and(Grouping::query()->where('bucket', 'repeat')->distinct()->count('hash'))->toBe(2);
    $this->artisan('storyfeed:curate --rebuild-bursts')->assertSuccessful();
    expect($snapshot())->toBe($before)
        ->and(Grouping::query()->where('bucket', 'composite')->orderBy('id')->get(['activity_id', 'hash', 'winner'])->toArray())->toBe($composites);
    $this->artisan('storyfeed:curate --rebuild-bursts --window=2')->assertFailed();
});
