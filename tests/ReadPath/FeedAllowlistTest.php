<?php

use Storyfeed\Exceptions\UnknownFeed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Builders\ActivityBuilder;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The scope + verb-allowlist seam: `only()` / `except()` as primitives, and
 * `Storyfeed::feeds([...])` as the declare-it-once registry a call site cannot
 * forget.
 *
 * The tests that matter here are not "does the whereIn work". They are:
 * (1) group counts and distinct-role counts recompute INSIDE the allowlist — an
 * allowlist that leaked "and 3 others" from excluded verbs would be worse than
 * no allowlist; (2) nothing downstream of a preset can widen it; (3) an app that
 * never calls feeds() sees no change at all.
 */
beforeEach(function () {
    $this->project = Customer::create(['name' => 'Port Migration']);
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

function verbsOf(array $items): array
{
    return collect($items)->pluck('verb')->sort()->values()->all();
}

it('restricts a feed to an allowlist of verbs', function () {
    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (['order.placed', 'order.delivered', 'order.margin_note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $page = $this->project->storyfeed()->only(['order.placed', 'order.delivered'])->log()->get();

    expect(verbsOf($page->items()))->toBe(['order.delivered', 'order.placed']);
});

it('excludes a denylist of verbs', function () {
    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (['order.placed', 'order.margin_note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $page = $this->project->storyfeed()->except(['order.margin_note'])->log()->get();

    expect(verbsOf($page->items()))->toBe(['order.placed']);
});

it('takes verb strings, FeedVerb cases and wildcards in one list', function () {
    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (['confirm', 'order.placed', 'order.paid', 'internal.note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $page = $this->project->storyfeed()
        ->only([ActivityVerb::Confirm, 'order.*'])
        ->log()
        ->get();

    expect(verbsOf($page->items()))->toBe(['confirm', 'order.paid', 'order.placed']);
});

it('never throws on a verb that exists nowhere in the registry', function () {
    // Verbs are free-form strings in storage by guarantee. An allowlist naming a
    // verb nobody ever declared or recorded is a query that matches nothing —
    // it is doctor's job to complain, not the read path's.
    Storyfeed::activity()->actor($this->ines)->verb('order.placed', $this->project)->publish();

    $page = $this->project->storyfeed()->only(['a.verb.that.never.was'])->log()->get();

    expect($page->items())->toBeEmpty();
});

it('treats a wildcard prefix literally, not as a LIKE pattern', function () {
    // Verbs may legally contain % and _, so an unescaped prefix would widen the
    // allowlist — the one direction a safety filter must never fail in.
    foreach (['a%b.leak', 'axb.leak'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $this->project)->publish();
    }

    $page = $this->project->storyfeed()->only(['a%b.*'])->log()->get();

    expect(verbsOf($page->items()))->toBe(['a%b.leak']);
});

it('refuses an empty allowlist rather than rendering an empty feed', function () {
    $this->project->storyfeed()->only([]);
})->throws(InvalidArgumentException::class, 'was given an empty list of verbs');

it('narrows on repeat calls instead of widening', function () {
    foreach (['order.placed', 'order.paid'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $this->project)->publish();
    }

    $page = $this->project->storyfeed()
        ->only(['order.placed', 'order.paid'])
        ->only(['order.paid'])
        ->log()
        ->get();

    // Union would give both; intersection is what makes a preset unwidenable.
    expect(verbsOf($page->items()))->toBe(['order.paid']);
});

it('recomputes group counts inside the allowlist', function () {
    // One actor, one repeat group. Three of the four activities are customer
    // verbs; the fourth must not survive in the count, which is the number the
    // headline renders as "and N others".
    $files = collect(range(1, 3))->map(
        fn (int $n) => Delivery::create(['tracking_number' => "photo-{$n}.jpg"]),
    );

    foreach ($files as $file) {
        Storyfeed::activity()->actor($this->ines)->verb('photo.approved', $file)->to($this->project)->publish();
    }

    Storyfeed::activity()->actor($this->ines)->verb('photo.approved', Delivery::create(
        ['tracking_number' => 'internal.jpg'],
    ))->to($this->project)->publish();

    $unfiltered = $this->project->storyfeed()->live()->get()->items()[0];

    // Exclude by object, inside a feed already narrowed by verb, so the group
    // spans the allowlist boundary in both dimensions.
    $filtered = $this->project->storyfeed()
        ->only(['photo.*'])
        ->query(fn (ActivityBuilder $q) => $q->whereNot('object_id', 4))
        ->live()
        ->get()
        ->items()[0];

    expect($unfiltered['count'])->toBe(4)
        ->and($filtered['kind'])->toBe('group')
        ->and($filtered['count'])->toBe(3)
        ->and($filtered['children'])->toHaveCount(3);
});

it('leaks no excluded actor into the distinct-role counts', function () {
    // ":actors and 3 others" comes from distinct.actors. An actor whose ONLY
    // activity is an excluded verb must not be counted there — this is the
    // specific leak that would make the allowlist worse than useless.
    $file = Delivery::create(['tracking_number' => 'proof.png']);
    $marcus = User::create(['name' => 'Marcus', 'email' => 'marcus@example.com']);
    $priya = User::create(['name' => 'Priya', 'email' => 'priya@example.com']);

    foreach ([$this->ines, $marcus] as $actor) {
        Storyfeed::activity()->actor($actor)->verb('order.confirmed', $file)->to($this->project)->publish();
    }

    Storyfeed::activity()->actor($priya)->verb('order.confirmed', $file)->to($this->project)->publish();
    Storyfeed::activity()->actor($priya)->verb('order.margin_note', $file)->to($this->project)->publish();

    $unfiltered = $this->project->storyfeed()->get()->items()[0];

    expect($unfiltered['distinct']['actors'])->toBe(3);

    // Priya's only allowed activity removed as well: she is now invisible to
    // this feed entirely, so the overflow count must say 2.
    $filtered = $this->project->storyfeed()
        ->only(['order.*'])
        ->except(['order.margin_note'])
        ->query(fn (ActivityBuilder $q) => $q->whereNot('actor_id', $priya->getKey()))
        ->get()
        ->items()[0];

    expect($filtered['distinct']['actors'])->toBe(2);
});

it('drops a group whose members are all excluded rather than emitting an empty node', function () {
    $file = Delivery::create(['tracking_number' => 'notes.txt']);

    foreach (range(1, 3) as $ignored) {
        Storyfeed::activity()->actor($this->ines)->verb('order.margin_note', $file)->to($this->project)->publish();
    }

    Storyfeed::activity()->actor($this->ines)->verb('order.placed', $this->project)->publish();

    $page = $this->project->storyfeed()->only(['order.placed'])->summary()->get();

    expect(verbsOf($page->items()))->toBe(['order.placed']);
});

it('cannot be widened by a query() callback using a top-level orWhere', function () {
    // AND binds tighter than OR, so an ungrouped callback would give
    // `... or (their thing and verb in (...))` and the excluded verb would come
    // straight back. The callbacks are nested precisely to make this impossible.
    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (['order.placed', 'order.margin_note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $page = $this->project->storyfeed()
        ->only(['order.placed'])
        ->query(fn (ActivityBuilder $q) => $q->where('verb', 'order.placed')->orWhere('verb', 'order.margin_note'))
        ->log()
        ->get();

    expect(verbsOf($page->items()))->toBe(['order.placed']);
});

it('still refuses a limit set inside a callback when a filter is active', function () {
    // The callbacks move into a nested group when a verb filter is present;
    // the limit/offset guard must still see them.
    Storyfeed::activity()->actor($this->ines)->verb('order.placed', $this->project)->publish();

    $this->project->storyfeed()
        ->only(['order.placed'])
        ->query(fn (ActivityBuilder $q) => $q->limit(1))
        ->get();
})->throws(InvalidArgumentException::class, 'set a limit or offset on the candidate activities');

it('changes nothing for a feed that declares no filter', function () {
    // The constraint the whole lane is built under: an app that never calls
    // feeds() or only() must see the feed it saw yesterday.
    foreach (['order.placed', 'order.margin_note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $this->project)->publish();
    }

    $page = $this->project->storyfeed()->log()->get();

    expect(verbsOf($page->items()))->toBe(['order.margin_note', 'order.placed']);
});

/* --- the registry ---------------------------------------------------- */

it('enters a registered preset by name from the facade and from a model', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['order.*'])->log(),
    ]);

    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (['order.placed', 'internal.note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $file)->to($this->project)->publish();
    }

    $viaFacade = Storyfeed::feed('customer')->involving($this->project)->get();
    $viaModel = $this->project->storyfeed('customer')->get();

    expect(verbsOf($viaFacade->items()))->toBe(['order.placed'])
        ->and(verbsOf($viaModel->items()))->toBe(['order.placed']);
});

it('accepts a preset closure that mutates without returning', function () {
    Storyfeed::feeds([
        'customer' => function (FeedBuilder $feed) {
            $feed->only(['order.*'])->log();
        },
    ]);

    Storyfeed::activity()->actor($this->ines)->verb('internal.note', $this->project)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('order.placed', $this->project)->publish();

    expect(verbsOf(Storyfeed::feed('customer')->get()->items()))->toBe(['order.placed']);
});

it('refuses a preset closure that returns something other than a builder', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => 'oops']);

    Storyfeed::feed('customer');
})->throws(InvalidArgumentException::class, 'returned string instead of a FeedBuilder');

it('throws on an unknown preset name instead of falling back to the whole feed', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.*'])]);

    Storyfeed::feed('custommer');
})->throws(UnknownFeed::class, 'Registered feeds: customer');

it('cannot be widened by a call site adding only() on top of a preset', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['order.placed'])->log(),
    ]);

    foreach (['order.placed', 'order.margin_note'] as $verb) {
        Storyfeed::activity()->actor($this->ines)->verb($verb, $this->project)->publish();
    }

    $page = Storyfeed::feed('customer')->only(['order.placed', 'order.margin_note'])->get();

    expect(verbsOf($page->items()))->toBe(['order.placed']);
});

it('lets a call site override the preset mode but not the allowlist', function () {
    // A preset carries two different kinds of thing: a safety property (the
    // allowlist) and a presentation default (the mode). Only one of them binds.
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['order.placed'])->log(),
    ]);

    $file = Delivery::create(['tracking_number' => 'menu.pdf']);

    foreach (range(1, 3) as $ignored) {
        Storyfeed::activity()->actor($this->ines)->verb('order.placed', $file)->to($this->project)->publish();
    }

    Storyfeed::activity()->actor($this->ines)->verb('order.margin_note', $file)->to($this->project)->publish();

    $log = Storyfeed::feed('customer')->involving($this->project)->get();
    $live = Storyfeed::feed('customer')->involving($this->project)->live()->get();

    expect($log->items())->toHaveCount(3)
        ->and($live->items()[0]['kind'])->toBe('group')
        ->and($live->items()[0]['count'])->toBe(3);
});

it('merges registrations by default and replaces when asked', function () {
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.*'])]);
    Storyfeed::feeds(['kitchen' => fn (FeedBuilder $feed) => $feed->only(['photo.*'])]);

    expect(Storyfeed::feedNames())->toBe(['customer', 'kitchen']);

    Storyfeed::feeds(['admin' => fn (FeedBuilder $feed) => $feed], merge: false);

    expect(Storyfeed::feedNames())->toBe(['admin']);
});

it('composes a preset with involving(), context() and a cursor', function () {
    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['order.*'])->log()->limit(2),
    ]);

    $other = Customer::create(['name' => 'Somebody Else']);

    foreach (range(1, 3) as $n) {
        Storyfeed::activity()->actor($this->ines)->verb('order.placed', $this->project)->publish();
    }

    Storyfeed::activity()->actor($this->ines)->verb('order.placed', $other)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('internal.note', $this->project)->publish();

    $first = $this->project->storyfeed('customer')->get();
    $second = $this->project->storyfeed('customer')->cursor($first->toArray()['next_cursor'])->get();

    $ids = collect($first->items())->concat($second->items())->pluck('id');

    expect($first->items())->toHaveCount(2)
        ->and($ids)->toHaveCount(3)
        ->and($ids->unique())->toHaveCount(3);
});
