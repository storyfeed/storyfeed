<?php

use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\Chronology;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * CHRONOLOGY IS KEPT AT WRITE, NOT RECONSTRUCTED AT READ.
 *
 * `published_at` was a whole-second column, so two activities published
 * milliseconds apart were tied in storage and the tiebreak decided their
 * order. The tiebreak was never a chronology question — the chronology had
 * been discarded before it ran. These tests pin the two halves of the fix:
 * the column carries microseconds AND the model writes them (either half
 * alone is a no-op that looks shipped), and every read compares at that
 * precision, cursors included.
 */

/**
 * One rename by its own actor. The repeat axis pins (actor, verb, object
 * TYPE, day), so one actor renaming several deliveries in a second is ONE
 * group in the grouped modes; a different actor per row keeps them distinct
 * items in every mode, which is what an ordering test needs.
 */
function renameBy(string $label, DateTimeInterface $at): Activity
{
    $user = User::create(['name' => $label, 'email' => strtolower($label).'@example.com']);

    return Storyfeed::activity()
        ->actor($user)
        ->verb('client.renamed', Delivery::create(['tracking_number' => $label]))
        ->publishedAt($at)
        ->publish();
}

/**
 * Two activities in one second, where the row published LATER has the LOWER
 * id — so any read that gets it right is ordering by the clock, not by the
 * tiebreak. Both in the past second, so the reader's `now()` admits them.
 *
 * @return array{0: Activity, 1: Activity} [$earlier, $later]
 */
function microsecondsApart(): array
{
    $second = now()->subSecond()->startOfSecond();

    $later = renameBy('LATER', $second->copy()->addMicroseconds(750));
    $earlier = renameBy('EARLIER', $second->copy()->addMicroseconds(250));

    expect($earlier->getKey())->toBeGreaterThan($later->getKey());

    return [$earlier, $later];
}

/** A page's item ids, in order. @return list<string> */
function itemIds(string $mode, ?string $cursor = null, int $limit = 30): array
{
    $feed = Storyfeed::feed()->{$mode}()->limit($limit);

    if ($cursor !== null) {
        $feed->cursor($cursor);
    }

    return array_column($feed->get()->toArray()['items'], 'id');
}

/** A cursor as it would have been minted before timestamps carried microseconds. */
function legacyCursor(string $encoded, string $key): string
{
    $parameters = Cursor::fromEncoded($encoded)->toArray();
    unset($parameters['_pointsToNextItems']);

    expect($parameters[$key])->toMatch('/\.\d{6}$/');

    $parameters[$key] = substr((string) $parameters[$key], 0, 19);

    return (new Cursor($parameters))->encode();
}

it('stores the microseconds it was given, and reads them back', function () {
    [, $later] = microsecondsApart();

    // The stored value, not the model's memory of it: the trap this guards
    // against is a wider column filled through a narrower format.
    $stored = (string) DB::table('feed_activities')->where('id', $later->getKey())->value('published_at');

    expect($stored)->toContain('.00075')
        ->and($later->fresh()->published_at->format('u'))->toBe('000750');
});

it('orders two activities in one second by the clock, not by id, in every read mode', function () {
    [$earlier, $later] = microsecondsApart();

    // Before the column carried microseconds both rows stored `:00`, the
    // tiebreak took over, and id DESC put the EARLIER row first — in log()
    // and in the grouped modes alike.
    foreach (['log', 'live', 'summary'] as $mode) {
        expect(itemIds($mode))->toBe([(string) $later->uid, (string) $earlier->uid], "mode: {$mode}");
    }
});

it('reads two activities from one second in the same order through log() and summary()', function () {
    microsecondsApart();

    expect(itemIds('summary'))->toBe(itemIds('log'))
        ->and(itemIds('live'))->toBe(itemIds('log'));
});

it('admits a row published microseconds before the reader looked', function () {
    // A Carbon bound straight into a WHERE is formatted at whole seconds by
    // the grammar, so `published_at <= now()` would compare `.400000` against
    // `.000000` and hide the row until the second turned over.
    $this->travelTo(now()->startOfSecond()->addMicroseconds(500_000));

    $activity = renameBy('JUSTNOW', now()->subMicroseconds(100_000));

    expect(itemIds('log'))->toBe([(string) $activity->uid])
        ->and(itemIds('live'))->toBe([(string) $activity->uid]);
});

it('pages through one second at microsecond positions without repeating or skipping', function () {
    $second = now()->subSecond()->startOfSecond();

    // Published out of id order on purpose: 300, 100, 200 microseconds.
    $uids = [];
    foreach ([300, 100, 200] as $micro) {
        $uids[$micro] = (string) renameBy("AT{$micro}", $second->copy()->addMicroseconds($micro))->uid;
    }

    $expected = [$uids[300], $uids[200], $uids[100]];

    foreach (['log', 'live', 'summary'] as $mode) {
        $seen = [];
        $cursor = null;

        do {
            $page = Storyfeed::feed()->{$mode}()->limit(1)->cursor($cursor)->get()->toArray();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        expect($seen)->toBe($expected, "mode: {$mode}");
    }
});

it('keeps paging correctly from a log() cursor minted before timestamps carried microseconds', function () {
    // Rows as an existing install holds them after the upgrade: whole
    // seconds, now stored as `.000000`. Three in one second, so the cursor
    // sits inside a tie — the case where a second-precision position could
    // land on the wrong side of a microsecond comparison.
    $second = now()->subSecond()->startOfSecond();

    $uids = [];
    foreach (['A', 'B', 'C'] as $label) {
        $uids[] = (string) renameBy($label, $second)->uid;
    }
    [$a, $b, $c] = $uids;

    $first = Storyfeed::feed()->log()->limit(1)->get()->toArray();
    expect($first['items'][0]['id'])->toBe($c);

    $legacy = legacyCursor($first['next_cursor'], 'feed_activities.published_at');

    expect(itemIds('log', $legacy, 1))->toBe([$b])
        ->and(itemIds('log', $legacy))->toBe([$b, $a]);
});

it('keeps paging correctly from a grouped cursor minted before timestamps carried microseconds', function () {
    $second = now()->subSecond()->startOfSecond();

    $uids = [];
    foreach (['A', 'B', 'C'] as $label) {
        $uids[] = (string) renameBy($label, $second)->uid;
    }

    // Once with the rows read as groups of one, once as solos (grouping rows
    // gone, as imported rows arrive) — the two cursor predicates differ.
    foreach (['group' => false, 'solo' => true] as $stream => $dropGroupings) {
        if ($dropGroupings) {
            Grouping::query()->delete();
        }

        $all = itemIds('live');
        expect($all)->toHaveCount(3, $stream);

        $first = Storyfeed::feed()->live()->limit(1)->get()->toArray();
        expect($first['items'][0]['id'])->toBe($all[0], $stream);

        $legacy = legacyCursor($first['next_cursor'], 'latest');

        expect(itemIds('live', $legacy))->toBe([$all[1], $all[2]], $stream);
    }
});

it('copies the activity precision into feed_participants', function () {
    [, $later] = microsecondsApart();

    $copy = (string) DB::table('feed_participants')->where('activity_id', $later->getKey())->value('published_at');

    expect($copy)->toContain('.00075');
});

it('does not change a grouping hash — grouping pins the day, which microseconds do not move', function () {
    $second = now()->subSecond()->startOfSecond();
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'SAME']);

    $whole = Storyfeed::activity()->actor($user)->verb('client.renamed', $delivery)
        ->publishedAt($second)->publish();
    $fractional = Storyfeed::activity()->actor($user)->verb('client.renamed', $delivery)
        ->publishedAt($second->copy()->addMicroseconds(999_999))->publish();

    $hashes = Grouping::query()
        ->whereIn('activity_id', [$whole->getKey(), $fractional->getKey()])
        ->where('bucket', '!=', 'batch')
        ->get()
        ->groupBy('bucket')
        ->map(fn ($rows) => $rows->pluck('hash')->unique()->count());

    expect($hashes)->not->toBeEmpty()
        ->and($hashes->every(fn (int $distinct) => $distinct === 1))->toBeTrue();
});

it('formats any date the way the column stores it', function () {
    $at = now()->startOfSecond()->addMicroseconds(42);

    expect(Chronology::stamp($at))->toBe($at->format('Y-m-d H:i:s').'.000042')
        ->and(Chronology::stamp(new DateTimeImmutable($at->toIso8601String())))->toBe($at->format('Y-m-d H:i:s').'.000000')
        ->and(Chronology::stamp('2026-09-10 11:59:59'))->toBe('2026-09-10 11:59:59.000000')
        ->and(Chronology::stamp('2026-09-10 11:59:59.5'))->toBe('2026-09-10 11:59:59.500000');
});
