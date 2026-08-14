<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Builders\ActivityBuilder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * `query()` is applied inside `filteredActivities()`, which every branch of the
 * read is built from. The tests that matter here are not "does the where work"
 * but the consequences of that placement: a constrained page must not produce a
 * group whose children contradict it, nor an overflow count that counts rows the
 * page excluded. Both come free from the placement and would rot silently if the
 * hook ever moved.
 */
beforeEach(function () {
    $this->project = Customer::create(['name' => 'Port Migration']);
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

it('narrows the page by excluding a verb', function () {
    $file = Delivery::create(['tracking_number' => 'style-tile.sketch']);

    Storyfeed::activity()->actor($this->ines)->verb('upload', $file)->to($this->project)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('comment', $file)->to($this->project)->publish();

    $page = $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->whereNot('verb', 'comment'))
        ->log()
        ->get();

    expect($page->items())->toHaveCount(1)
        ->and($page->items()[0]['verb'])->toBe('upload');
});

it('narrows the page by a date window', function () {
    $file = Delivery::create(['tracking_number' => 'wireframes.sketch']);

    Storyfeed::activity()->actor($this->ines)->verb('upload', $file)
        ->to($this->project)->publishedAt(now()->subMonth())->publish();
    Storyfeed::activity()->actor($this->ines)->verb('revise', $file)
        ->to($this->project)->publish();

    $page = $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->where('published_at', '>=', now()->subWeek()))
        ->log()
        ->get();

    expect($page->items())->toHaveCount(1)
        ->and($page->items()[0]['verb'])->toBe('revise');
});

it('composes two callbacks', function () {
    $file = Delivery::create(['tracking_number' => 'hero.fig']);

    foreach (['upload', 'revise', 'comment'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $page = $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->whereNot('verb', 'comment'))
        ->query(fn (ActivityBuilder $q) => $q->whereNot('verb', 'revise'))
        ->log()
        ->get();

    expect($page->items())->toHaveCount(1)
        ->and($page->items()[0]['verb'])->toBe('upload');
});

it('keeps group children consistent with the constraint', function () {
    // One actor uploading several files groups on the repeat axis. Excluding one
    // of those files must shrink the group, not just hide a row from the page.
    $kept = collect(range(1, 3))->map(
        fn (int $n) => Delivery::create(['tracking_number' => "keep-{$n}.fig"]),
    );
    $dropped = Delivery::create(['tracking_number' => 'drop.fig']);

    foreach ($kept->push($dropped) as $file) {
        Storyfeed::activity()->actor($this->ines)->verb('upload', $file)->to($this->project)->publish();
    }

    $unfiltered = $this->project->storyfeed()->live()->get()->items()[0];

    $filtered = $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->whereNot('object_id', $dropped->getKey()))
        ->live()
        ->get()
        ->items()[0];

    expect($unfiltered['kind'])->toBe('group')
        ->and($unfiltered['count'])->toBe(4)
        ->and($filtered['kind'])->toBe('group')
        // The count is recomputed against the constrained candidates.
        ->and($filtered['count'])->toBe(3);

    $childIds = collect($filtered['children'])->pluck('object.id');

    expect($childIds)->not->toContain((string) $dropped->getKey());
});

it('keeps distinct-role counts consistent with the constraint', function () {
    // Three actors on one target groups on the actors axis, and the headline's
    // overflow comes from distinct.actors — which must not count an actor whose
    // only activity the constraint removed.
    $file = Delivery::create(['tracking_number' => 'proof-sheet.png']);
    $marcus = User::create(['name' => 'Marcus', 'email' => 'marcus@example.com']);
    $priya = User::create(['name' => 'Priya', 'email' => 'priya@example.com']);

    foreach ([$this->ines, $marcus, $priya] as $actor) {
        Storyfeed::activity()->actor($actor)->verb('approve', $file)->to($this->project)->publish();
    }

    $unfiltered = $this->project->storyfeed()->get()->items()[0];

    $filtered = $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->whereNot('actor_id', $priya->getKey()))
        ->get()
        ->items()[0];

    expect($unfiltered['distinct']['actors'])->toBe(3)
        ->and($filtered['distinct']['actors'])->toBe(2);
});

it('refuses a callback that limits the candidate activities', function () {
    Storyfeed::activity()->actor($this->ines)->verb('upload', $this->project)->publish();

    $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->limit(1))
        ->get();
})->throws(
    InvalidArgumentException::class,
    'A query() callback set a limit or offset on the candidate activities',
);

it('refuses a callback that offsets the candidate activities', function () {
    Storyfeed::activity()->actor($this->ines)->verb('upload', $this->project)->publish();

    $this->project->storyfeed()
        ->query(fn (ActivityBuilder $q) => $q->offset(5))
        ->get();
})->throws(InvalidArgumentException::class);

it('is unharmed by a callback that adds its own ordering', function () {
    foreach (range(1, 3) as $n) {
        Storyfeed::activity()
            ->actor($this->ines)
            ->verb('upload', Delivery::create(['tracking_number' => "file-{$n}.fig"]))
            ->to($this->project)
            ->publish();
    }

    $constrain = fn (ActivityBuilder $q) => $q->orderBy('id');

    $first = $this->project->storyfeed()->query($constrain)->log()->limit(2)->get();
    $second = $this->project->storyfeed()->query($constrain)->log()->limit(2)
        ->cursor($first->toArray()['next_cursor'])->get();

    $ids = collect($first->items())->concat($second->items())->pluck('id');

    expect($ids)->toHaveCount(3)
        ->and($ids->unique())->toHaveCount(3);
});

it('runs the callback once per branch of the read, with no side effects assumed', function () {
    // The docblock claims a callback runs several times per page. This pins the
    // real number so the claim is measured rather than estimated.
    $file = Delivery::create(['tracking_number' => 'counted.fig']);

    foreach (range(1, 2) as $n) {
        Storyfeed::activity()->actor($this->ines)->verb('upload', $file)->to($this->project)->publish();
    }

    $log = 0;
    $this->project->storyfeed()->query(function () use (&$log) {
        $log++;
    })->log()->get();

    $grouped = 0;
    $this->project->storyfeed()->query(function () use (&$grouped) {
        $grouped++;
    })->summary()->get();

    // Pinned for this fixture: one group and no solo rows. A grouped page runs
    // the group stream, the solo stream, the member fetch and one count per role.
    // If these numbers move, the docblock on FeedBuilder::query() is now wrong.
    expect($log)->toBe(1)
        ->and($grouped)->toBe(7);
});
