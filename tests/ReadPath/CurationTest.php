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
        ->and($items[0]['exemplars']['targets'][0]['label'])->toBe('Concur')
        ->and($items[0]['distinct']['actors'])->toBe(3)
        ->and($items[0]['exemplars']['targets'])->toHaveCount(1); // pinned role: list of one, by construction
});

it('reports true distinct counts, not just the ones nested in capped children', function () {
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
        ->and($item['distinct']['actors'])->toBe(5);
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

it('suppresses a singular fallback whose tokens would lie about the group', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object']);

    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        uploadsTo($project, $name);
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // Three actors, one head member: ":actor uploaded :object" would credit
    // the whole group to one person over one file — the lie class arriving
    // through the fallback door (found live by the Newsroom). No headline
    // beats a wrong one; the renderer's generic group treatment takes over.
    expect($item['axis'])->toBe('actors')
        ->and($item['headline_template'])->toBeNull()
        ->and($item['headline'])->toBeNull();
});

it('admits a singular fallback whose tokens are all pinned by the axis', function () {
    Storyfeed::grammar(['delivery.revise' => ':actor revised :object']);

    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The object axis pins :actor AND :object, so the singular template is
    // homogeneous across members — an honest fallback, admitted.
    expect($item['axis'])->toBe('object')
        ->and($item['headline_template'])->toBe(':actor revised :object');
});

it('never uses a closure singular fallback for a group', function () {
    Storyfeed::grammar(['delivery.revise' => fn ($activity) => "Somebody revised {$activity->object_id}"]);

    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($bob)->verb('revise', $doc)->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // A closure pre-renders from ONE member and cannot be token-inspected;
    // even on a fully-pinned axis it is not admitted for groups.
    expect($item['kind'])->toBe('group')
        ->and($item['headline_template'])->toBeNull()
        ->and($item['headline'])->toBeNull();
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
        ->and($items[0]['exemplars']['objects'][0]['label'])->toBe('Delivery #Aut Beatae.docx')
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
        ->and($items->firstWhere('axis', 'object')['exemplars']['objects'][0]['label'])->toBe('Delivery #Aut Beatae.docx')
        ->and($items->firstWhere('axis', 'repeat')['count'])->toBe(3)
        ->and($items->firstWhere('axis', 'repeat')['exemplars']['objects'])->toHaveCount(3);
});

it('keeps distinct objects on the repeat axis', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // Object clusters of one are ineligible: "Sally uploaded 3 photos"
    // stays a repeat story — no SINGULAR object, but the collapsed
    // dimension is nameable as a list now.
    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['exemplars']['objects'])->toHaveCount(3)
        ->and($items[0]['distinct']['objects'])->toBe(3);
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

it('names the collapsed projects on a targets-axis group — the "added 5 items" fix', function () {
    Storyfeed::aggregateGrammar(['targets.add' => ':actor added :count items in :targets']);

    $sally = User::create(['name' => 'Sally Nguyen', 'email' => 'sn@example.com']);

    foreach (['Onboarding Portal', 'Analytics Dashboard', 'Brand Refresh', 'Spring Campaign'] as $name) {
        Storyfeed::activity()
            ->actor($sally)
            ->verb('add', Delivery::create(['tracking_number' => "task-{$name}"]))
            ->for(Customer::create(['name' => $name]))
            ->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The screenshot's vagueness, fixed: the collapsed dimension is listed.
    expect($item['axis'])->toBe('targets')
        ->and($item['headline_template'])->toBe(':actor added :count items in :targets')
        ->and($item['exemplars']['targets'])->toHaveCount(3)
        ->and($item['distinct']['targets'])->toBe(4)
        // Exemplars draw newest-first from the capped members, so the most
        // recent project leads and the oldest overflows into `distinct`.
        ->and(collect($item['exemplars']['targets'])->pluck('label'))->toContain('Spring Campaign')
        ->and($item['exemplars']['actors'])->toHaveCount(1); // pinned: Sally, list of one
});

it('names the collapsed tasks on a repeat-axis group — the "completed 3 tasks" fix', function () {
    Storyfeed::aggregateGrammar(['repeat.complete' => ':actor completed :objects']);

    $sally = User::create(['name' => 'Sally Nguyen', 'email' => 'sn@example.com']);

    foreach (['Et iure ab', 'Impedit laudantium nemo', 'Id distinctio voluptas qui'] as $name) {
        Storyfeed::activity()->actor($sally)->verb('complete', Delivery::create(['tracking_number' => $name]))->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['axis'])->toBe('repeat')
        ->and($item['headline_template'])->toBe(':actor completed :objects')
        ->and($item['exemplars']['objects'])->toHaveCount(3)
        ->and($item['distinct']['objects'])->toBe(3);
});
