<?php

use Storyfeed\Actions\CurateCluster;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function uploadsTo(Customer $project, string $actor, int $files = 1): void
{
    $user = User::firstOrCreate(
        ['email' => strtolower($actor).'@example.com'],
        ['name' => $actor],
    );

    foreach (range(1, $files) as $i) {
        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "{$actor}-{$i}"]))
            ->for($project)
            ->publish();
    }
}

it('collapses on the actors axis once enough distinct actors join', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // "Bob, Sally and Ann uploaded files to Concur" — one node, three actors.
    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('actors')
        ->and($items[0]['count'])->toBe(3)
        ->and($items[0]['exemplars']['actors'])->toHaveCount(3)
        ->and($items[0]['exemplars']['target']['label'])->toBe('Concur')
        ->and($items[0]['others_count'])->toBe(0);
});

it('reports others_count from all actors, not just the ones nested in children', function () {
    config()->set('storyfeed.grouping.children_limit', 2);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann', 'Ravi', 'Mo'] as $name) {
        uploadsTo($project, $name);
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['axis'])->toBe('actors')
        ->and($item['count'])->toBe(5)
        ->and($item['children'])->toHaveCount(2)
        ->and($item['children_truncated'])->toBeTrue()
        ->and($item['exemplars']['actors'])->toHaveCount(2)
        ->and($item['others_count'])->toBe(3);
});

it('keeps one actor repeating on the repeat axis, not targets', function () {
    $project = Customer::create(['name' => 'Concur']);

    // The coin-flip case: this is a repeat cluster of 3 AND a targets
    // cluster of 3, but only ONE distinct target — collapsing targets would
    // say "Sally uploaded to 1 project".
    uploadsTo($project, 'Sally', files: 3);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(3);
});

it('collapses on the targets axis across distinct targets', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (['Concur', 'Beacon', 'Vela'] as $name) {
        Storyfeed::activity()
            ->actor($sally)
            ->verb('comment', Delivery::create(['tracking_number' => $name]))
            ->for(Customer::create(['name' => $name]))
            ->publish();
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('targets')
        ->and($items[0]['count'])->toBe(3);
});

it('stamps exactly one winner per activity', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $counts = Grouping::query()
        ->where('winner', true)
        ->selectRaw('activity_id, count(*) as winners')
        ->groupBy('activity_id')
        ->pluck('winners', 'activity_id');

    expect($counts)->toHaveCount(3)
        ->and($counts->unique()->values()->all())->toBe([1]);
});

it('is idempotent — curating twice produces identical rows', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $before = Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();

    $curate = new CurateCluster;

    foreach (Activity::query()->get() as $activity) {
        $curate($activity);
        $curate($activity);
    }

    $after = Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();

    expect($after)->toBe($before);
});

it('re-decides the cluster when a delete drops it below threshold', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('actors');

    Activity::query()->whereHas('cachedActor', fn ($q) => $q->where('label', 'Ann'))->first()->forceDelete();

    // Two actors is below min_actors, so the group must fall apart rather
    // than keep claiming an axis it no longer earns. Each remaining upload
    // is a repeat cluster of one, which renders as a plain activity node.
    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(2)
        ->and(collect($items)->pluck('kind')->unique()->values()->all())->toBe(['activity'])
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0)
        ->and(Grouping::query()->where('bucket', 'repeat')->where('winner', true)->count())->toBe(2);
});

it('reads uncurated rows as repeat groups, with no backfill cliff', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    // An adopter's rows, migrated but never curated.
    Grouping::query()->update(['winner' => null]);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(3);
});

it('backfills winners with storyfeed:curate', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    Grouping::query()->update(['winner' => null]);

    $this->artisan('storyfeed:curate')->assertSuccessful();

    expect(Grouping::query()->where('winner', true)->where('bucket', 'actors')->count())->toBe(3)
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('actors');
});

it('renders an aggregate headline for the winning axis', function () {
    Storyfeed::aggregateGrammar([
        'actors.upload' => ':actors uploaded :count files to :target',
    ]);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['headline_template'])->toBe(':actors uploaded :count files to :target');
});

it('falls back to the singular headline when no aggregate grammar is registered', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object']);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['axis'])->toBe('actors')
        ->and($item['headline_template'])->toBe(':actor uploaded :object');
});

it('returns a flat log of atomic activities with ->flat()', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    uploadsTo($project, 'Sally', files: 3);

    // Groups exist (curation stamped winners) — but flat means FLAT: no
    // group nodes at all, the honest reading of the name. Log mode.
    $items = Storyfeed::feed()->flat()->limit(10)->get()->toArray()['items'];

    expect($items)->toHaveCount(6)
        ->and(collect($items)->pluck('kind')->unique()->values()->all())->toBe(['activity']);
});

it('lets the last mode call win', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $items = Storyfeed::feed()->flat()->curated()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('actors');
});

it('returns repeat-only groups with ->grouped(), ignoring stamped winners', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    // Curation stamped an actors winner; grouped mode is the proven middle
    // tier and reads the repeat axis regardless — the pre-flip default,
    // back as a per-view choice.
    $items = Storyfeed::feed()->grouped()->get()->toArray()['items'];

    expect($items)->toHaveCount(3)
        ->and(collect($items)->pluck('kind')->unique()->values()->all())->toBe(['activity'])
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(3);
});

it('reads the app-wide default mode from config', function () {
    config()->set('storyfeed.grouping.default', 'grouped');

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    expect(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(3)
        ->and(Storyfeed::feed()->curated()->get()->toArray()['items'])->toHaveCount(1);
});

it('rejects unknown feed modes', function () {
    config()->set('storyfeed.grouping.default', 'chronological');

    Storyfeed::activity()->verb('ping')->publish();

    expect(fn () => Storyfeed::feed()->get())->toThrow(InvalidArgumentException::class, 'chronological');
});

it('degrades to classic repeat-only grouping app-wide when curation is disabled', function () {
    config()->set('storyfeed.grouping.curate', false);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    uploadsTo($project, 'Sally', files: 3);

    // No winners stamped anywhere, so the default feed's per-activity
    // fallback yields the pre-package behaviour: repeats collapse, nothing
    // multi-axis. The middle tier is an app-wide policy, not a view flag.
    $items = Storyfeed::feed()->get()->toArray()['items'];

    // Sally's 4 uploads share one repeat hash → one group; Bob and Ann solo.
    expect(Grouping::query()->whereNotNull('winner')->count())->toBe(0)
        ->and($items)->toHaveCount(3)
        ->and(collect($items)->where('kind', 'group')->count())->toBe(1)
        ->and(collect($items)->firstWhere('kind', 'group')['axis'])->toBe('repeat')
        ->and(collect($items)->firstWhere('kind', 'group')['count'])->toBe(4);
});

it('collapses repeated acts on one object onto the object axis', function () {
    Storyfeed::aggregateGrammar(['object.revise' => ':actor made :count revisions to :object']);

    $bob = User::create(['name' => 'Bob Callahan', 'email' => 'bob@example.com']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    foreach (range(1, 5) as $i) {
        Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // The screenshot's story, told truthfully: one object, pinned, nameable.
    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('object')
        ->and($items[0]['count'])->toBe(5)
        ->and($items[0]['exemplars']['object']['label'])->toBe('Delivery #Aut Beatae.docx')
        ->and($items[0]['headline_template'])->toBe(':actor made :count revisions to :object');
});

it('fragments a mixed day into precise stories instead of one wrong one', function () {
    $bob = User::create(['name' => 'Bob Callahan', 'email' => 'bob@example.com']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    // The dysfunctional screenshot: 2 revisions to one doc + 3 other docs.
    // One repeat group would have to lie either way ("5 revisions to Aut
    // Beatae" or "revised 5 documents"); two groups tell two true stories.
    Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();
    Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($bob)->verb('revise', Delivery::create(['tracking_number' => "Other-{$i}.pdf"]))->publish();
    }

    $items = collect(Storyfeed::feed()->limit(10)->get()->toArray()['items']);

    expect($items)->toHaveCount(2)
        ->and($items->pluck('axis')->sort()->values()->all())->toBe(['object', 'repeat'])
        ->and($items->firstWhere('axis', 'object')['count'])->toBe(2)
        ->and($items->firstWhere('axis', 'object')['exemplars']['object']['label'])->toBe('Delivery #Aut Beatae.docx')
        ->and($items->firstWhere('axis', 'repeat')['count'])->toBe(3)
        ->and($items->firstWhere('axis', 'repeat')['exemplars']['object'])->toBeNull();
});

it('keeps distinct objects on the repeat axis', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // Object clusters of one are ineligible: "Sally uploaded 3 photos"
    // stays a repeat story, with no object named.
    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['exemplars']['object'])->toBeNull();
});

it('lets actors beat object when both are eligible', function () {
    $project = Customer::create(['name' => 'Concur']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::firstOrCreate(['email' => strtolower($name).'@example.com'], ['name' => $name]);
        Storyfeed::activity()->actor($user)->verb('revise', $doc)->for($project)->publish();
    }

    $bob = User::firstOrCreate(['email' => 'bob@example.com'], ['name' => 'Bob']);
    Storyfeed::activity()->actor($bob)->verb('revise', $doc)->for($project)->publish();

    // Bob's pair makes the object axis eligible for his two rows, but three
    // distinct actors make the social story — priority is the tie-break.
    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('actors')
        ->and($items[0]['count'])->toBe(4);
});

it('backfills a newly added axis with storyfeed:curate --rehash', function () {
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();
    }

    // Simulate rows published before the object axis existed.
    Grouping::query()->where('bucket', 'object')->delete();
    Activity::query()->get()->each(fn ($a) => (new CurateCluster)($a));

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('repeat');

    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    expect(Grouping::query()->where('bucket', 'object')->count())->toBe(3)
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('object');
});
