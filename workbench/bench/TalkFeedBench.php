<?php

/*
 * Does curation survive a talk-shaped feed? (W112)
 *
 *   vendor/bin/pest workbench/bench/TalkFeedBench.php
 *
 * Two conference talks end by replaying their own feed to the room, first in
 * `->log()` and then in `->summary()`. Every shipped axis pins the publication
 * day, and `repeat` pins actor and target — so forty slide advances by one
 * speaker on one talk on one day may collapse into a single row, and the two
 * `replay.played` rows in the final minute (same verb, actor, target, day)
 * may render as "played 2 replays". Nobody had put a dense same-actor feed in
 * front of the curator before this harness.
 *
 * This is MEASUREMENT ONLY. It seeds a synthetic ~40-minute talk under the
 * shipped policy and defaults, runs `storyfeed:curate`, reads both modes, and
 * prints what each beat renders as, which axis won, and what it cost. It
 * changes no policy: a policy change is an owner decision and this is the
 * evidence for it.
 *
 * Cast (workbench stand-ins, since the talk app's models do not live here):
 *
 *   Customer  — a talk  (Waterloo, London)
 *   Delivery  — a slide (labels read "Delivery #Slide 12"; cosmetic)
 *   User      — the speaker and every attendee, named
 *   Party     — an easter egg ("the cat")
 *
 * Verb/role grammar follows docs/held/talk-feed-spec.md §4. Output goes to
 * STDERR so PHPUnit's output strictness leaves it alone.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

uses(TestCase::class);

/** @return array{total: int, ms: float, shapes: array<string, int>} */
function talk_measure(Closure $work): array
{
    $shapes = [];
    $total = 0;

    DB::listen(function ($query) use (&$shapes, &$total): void {
        $total++;
        $sql = preg_replace(['/\d+/', '/\s+/', '/in \([^)]*\)/'], ['?', ' ', 'in (…)'], $query->sql);
        $sql = substr($sql, 0, 96);
        $shapes[$sql] = ($shapes[$sql] ?? 0) + 1;
    });

    $started = hrtime(true);
    $work();
    $ms = (hrtime(true) - $started) / 1e6;

    DB::connection()->getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
    arsort($shapes);

    return ['total' => $total, 'ms' => $ms, 'shapes' => $shapes];
}

function talk_grammar(): void
{
    Storyfeed::grammar([
        '*.talk.started' => ':actor started :target',
        '*.talk.ended' => ':actor ended :target',
        '*.slide.advanced' => ':actor moved to :result',
        '*.audience.joined' => ':actor joined',
        '*.audience.reacted' => ':actor reacted to :object',
        '*.audience.found' => ':actor found :object on :target',
        '*.device.lost' => ":actor's phone dropped",
        '*.device.returned' => ":actor's phone came back",
        '*.replay.played' => ':actor played back :object',
    ]);

    Storyfeed::aggregateGrammar([
        'actors.audience.joined' => ':actors joined',
        'actors.audience.reacted' => ':actors reacted to :objects',
        'actors.audience.found' => ':actors found :object',
        'actors.device.lost' => ":actors' phones dropped",
        'actors.device.returned' => ":actors' phones came back",
        'repeat.slide.advanced' => ':actor moved through :count slides',
        'repeat.replay.played' => ':actor played :count replays',
        'targets.audience.reacted' => ':actor reacted to :count slides',
        'object.audience.reacted' => ':actor reacted to :object :count times',
        'object.slide.advanced' => ':actor moved to :object :count times',
    ]);
}

/**
 * Seed ~40 minutes of one talk. Returns the handles the probes need.
 *
 * @return array{talk: Customer, waterloo: Customer, speaker: User, start: Carbon, end: Carbon, count: int}
 */
function talk_seed(): array
{
    talk_grammar();

    $end = now();                    // TestCase froze the clock at midday
    $start = $end->copy()->subMinutes(40);
    $count = 0;

    $waterloo = Customer::create(['name' => 'Waterloo']);
    $talk = Customer::create(['name' => 'London']);
    $speaker = User::create(['name' => 'Jasper', 'email' => 'speaker@talk.test']);
    $slides = collect(range(1, 41))->map(fn ($i) => Delivery::create(['tracking_number' => "Slide {$i}"]));
    $attendees = collect(range(1, 60))->map(fn ($i) => User::create(['name' => "Attendee {$i}", 'email' => "a{$i}@talk.test"]));
    $eggs = collect(['the cat', 'the fox', 'the owl'])->map(fn ($name) => Storyfeed::party($name));

    $publish = function (string $verb, User $actor, Carbon $at) use (&$count) {
        $count++;

        return Storyfeed::activity($verb)->actor($actor)->publishedAt($at);
    };

    // talk.started — speaker, target talk, context talk.
    $publish('talk.started', $speaker, $start)->target($talk)->context($talk)->publish();

    // slide.advanced ×40 — one actor, one target, ~a minute apart; origin the
    // slide left, result the slide arrived at (§4: no object).
    $advancedAt = [];
    foreach (range(1, 40) as $i) {
        $at = $start->copy()->addSeconds((int) round($i * 57.5));
        $advancedAt[$i] = $at;
        $publish('slide.advanced', $speaker, $at)
            ->target($talk)->context($talk)
            ->origin($slides[$i - 1])->result($slides[$i])
            ->publish();
    }

    // audience.joined ×60 — clustered in the first five minutes.
    foreach ($attendees as $n => $attendee) {
        $at = $start->copy()->addSeconds(5 + (int) (($n / 60) ** 2 * 295));
        $publish('audience.joined', $attendee, $at)->target($talk)->context($talk)->publish();
    }

    // audience.reacted ×200 — spread across slides; object = the slide showing.
    mt_srand(112);
    foreach (range(1, 200) as $r) {
        $slide = mt_rand(1, 40);
        $at = $advancedAt[$slide]->copy()->addSeconds(mt_rand(3, 50));
        $publish('audience.reacted', $attendees[mt_rand(0, 59)], $at)
            ->object($slides[$slide])->target($talk)->context($talk)
            ->data(['reaction' => ['following', 'good', 'lost', 'ask'][mt_rand(0, 3)]])
            ->publish();
    }

    // audience.found ×15 — egg on a slide, target the slide, context the talk.
    // Three eggs on three slides; the cat on slide 9 is found by two people
    // (below min_actors: 3), the others by more.
    $finds = [[9, 0, 2], [17, 1, 5], [31, 2, 8]];
    foreach ($finds as [$slide, $egg, $finders]) {
        foreach (range(1, $finders) as $f) {
            $at = $advancedAt[$slide]->copy()->addSeconds(5 + $f * 4);
            $publish('audience.found', $attendees[($slide * 7 + $f) % 60], $at)
                ->object($eggs[$egg])->target($slides[$slide])->context($talk)
                ->publish();
        }
    }

    // device.lost / device.returned ×12 interleaved — six phones, each drops
    // once and comes back a couple of minutes later; origin the slide showing.
    foreach (range(1, 6) as $d) {
        $slide = $d * 6;
        $who = $attendees[$d * 9];
        $publish('device.lost', $who, $advancedAt[$slide]->copy()->addSeconds(20))
            ->target($talk)->context($talk)->origin($slides[$slide])->publish();
        $publish('device.returned', $who, $advancedAt[$slide + 2]->copy()->addSeconds(10))
            ->target($talk)->context($talk)->origin($slides[$slide + 2])->publish();
    }

    // The finale: two replay.played in the last 60 seconds. Same verb, same
    // actor, same target, seconds apart. First Waterloo's record, then this
    // talk's own.
    $publish('replay.played', $speaker, $end->copy()->subSeconds(50))
        ->object($waterloo)->target($talk)->context($talk)->publish();
    $publish('replay.played', $speaker, $end->copy()->subSeconds(8))
        ->object($talk)->target($talk)->context($talk)->publish();

    // talk.ended.
    $publish('talk.ended', $speaker, $end)->target($talk)->context($talk)->publish();

    return compact('talk', 'waterloo', 'speaker', 'start', 'end', 'count');
}

/**
 * A throwaway renderer: the payload carries `headline_template` plus entities
 * and exemplars, and rendering is the renderer package's job. Enough here to
 * read a row aloud.
 *
 * @param  array<string, mixed>  $item
 */
function talk_headline(array $item): string
{
    if (($item['headline'] ?? null) !== null) {
        return (string) $item['headline'];
    }

    $template = $item['headline_template'] ?? null;

    if ($template === null) {
        return '(null headline)';
    }

    $label = fn ($entity) => is_array($entity) ? ($entity['label'] ?? '?') : '?';

    $tokens = [':count' => (string) ($item['count'] ?? 1)];

    foreach (['actor', 'object', 'target', 'context', 'origin', 'result'] as $role) {
        $tokens[":{$role}"] = $label($item[$role] ?? null);
        $plural = "{$role}s";
        $list = array_map($label, $item['exemplars'][$plural] ?? []);
        $distinct = $item['distinct'][$plural] ?? count($list);
        $others = $distinct - count($list);
        $tokens[":{$plural}"] = implode(', ', $list).($others > 0 ? " and {$others} others" : '');
    }

    // Longest tokens first so :actors is not eaten by :actor.
    uksort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));

    return strtr($template, $tokens);
}

/** @param array<int, array<string, mixed>> $items */
function talk_print(string $label, array $items): void
{
    fwrite(STDERR, "\n== {$label}: ".count($items)." rows ==\n");

    foreach ($items as $item) {
        $time = substr((string) $item['published_at'], 11, 8);

        if ($item['kind'] === 'group') {
            fwrite(STDERR, sprintf("  %s  [%-7s ×%-3d] %s\n", $time, $item['axis'], $item['count'], talk_headline($item)));
        } else {
            fwrite(STDERR, sprintf("  %s  [%-16s] %s\n", $time, $item['verb'], talk_headline($item)));
        }
    }
}

/**
 * Read the whole talk in one mode, following cursors, and return every row.
 *
 * @return array{items: array<int, array<string, mixed>>, pages: int}
 */
function talk_read(string $mode, Customer $talk): array
{
    $items = [];
    $cursor = null;
    $pages = 0;

    do {
        $page = Storyfeed::feed()->involving($talk)->limit(100)->cursor($cursor)->{$mode}()->get();
        $items = [...$items, ...$page->items()];
        $cursor = $page->nextCursor();
        $pages++;
    } while ($cursor !== null && $pages < 20);

    return ['items' => $items, 'pages' => $pages];
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @return array<int, array<string, mixed>>
 */
function talk_rows_for(array $items, string $verb): array
{
    return array_values(array_filter($items, fn ($item) => $item['verb'] === $verb));
}

/** @param array<int, array<string, mixed>> $rows */
function talk_describe(array $rows): string
{
    if ($rows === []) {
        return 'ABSENT';
    }

    $kinds = [];
    foreach ($rows as $row) {
        $kinds[] = $row['kind'] === 'group'
            ? "group[{$row['axis']} ×{$row['count']}] \"".talk_headline($row).'"'
            : 'activity "'.talk_headline($row).'"';
    }

    return count($rows).' row(s): '.implode('; ', array_slice($kinds, 0, 3)).(count($rows) > 3 ? '; …' : '');
}

it('measures the talk-shaped feed under the shipped curation policy', function () {
    $fixture = null;
    $seed = talk_measure(function () use (&$fixture) {
        $fixture = talk_seed();
    });
    $curate = talk_measure(fn () => Artisan::call('storyfeed:curate'));

    $n = $fixture['count'];
    fwrite(STDERR, sprintf(
        "\nseed:   n=%d  %6d queries (%5.1f/act)  %7.0f ms\ncurate: n=%d  %6d queries (%5.1f/act)  %7.0f ms\n",
        $n, $seed['total'], $seed['total'] / $n, $seed['ms'],
        $n, $curate['total'], $curate['total'] / $n, $curate['ms'],
    ));
    foreach (array_slice($curate['shapes'], 0, 5, true) as $sql => $c) {
        fwrite(STDERR, sprintf("    %6d  %s\n", $c, $sql));
    }

    // Which axis won, per verb, straight from the stamps.
    $winners = DB::table('feed_groupings')
        ->join('feed_activities', 'feed_activities.id', '=', 'feed_groupings.activity_id')
        ->where('winner', true)
        ->selectRaw('verb, bucket, count(*) as n, count(distinct hash) as clusters')
        ->groupBy('verb', 'bucket')->orderBy('verb')->get();
    fwrite(STDERR, "\n== winners by verb (stamps) ==\n");
    foreach ($winners as $w) {
        fwrite(STDERR, sprintf("  %-17s %-8s %4d members in %3d cluster(s)\n", $w->verb, $w->bucket, $w->n, $w->clusters));
    }

    $reads = [];
    foreach (['log', 'live', 'summary'] as $mode) {
        $measured = talk_measure(function () use (&$reads, $mode, $fixture) {
            $reads[$mode] = talk_read($mode, $fixture['talk']);
        });
        fwrite(STDERR, sprintf(
            "\nread ->%s(): %d rows over %d page(s)  %4d queries  %6.0f ms\n",
            $mode, count($reads[$mode]['items']), $reads[$mode]['pages'], $measured['total'], $measured['ms'],
        ));
    }

    talk_print('->summary()', $reads['summary']['items']);

    $log = $reads['log']['items'];
    $summary = $reads['summary']['items'];
    $finalMinute = array_values(array_filter($summary, fn ($i) => $i['published_at'] >= $fixture['end']->copy()->subSeconds(60)->toISOString()));

    fwrite(STDERR, "\n== the five questions ==\n");
    fwrite(STDERR, '  1. slide.advanced ×40  log: '.talk_describe(talk_rows_for($log, 'slide.advanced'))."\n");
    fwrite(STDERR, '                         summary: '.talk_describe(talk_rows_for($summary, 'slide.advanced'))."\n");
    fwrite(STDERR, '  2. audience.joined ×60 log: '.talk_describe(talk_rows_for($log, 'audience.joined'))."\n");
    fwrite(STDERR, '                         summary: '.talk_describe(talk_rows_for($summary, 'audience.joined'))."\n");
    fwrite(STDERR, '  3. replay.played ×2    log: '.talk_describe(talk_rows_for($log, 'replay.played'))."\n");
    fwrite(STDERR, '                         live: '.talk_describe(talk_rows_for($reads['live']['items'], 'replay.played'))."\n");
    fwrite(STDERR, '                         summary: '.talk_describe(talk_rows_for($summary, 'replay.played'))."\n");
    talk_print('final 60 seconds, ->summary()', $finalMinute);

    expect($n)->toBe(1 + 40 + 60 + 200 + 15 + 12 + 2 + 1)
        ->and(count($log))->toBe($n);
});
