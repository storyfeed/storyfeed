<?php

/*
 * W120 probe — what makes the two replay.played rows stay two rows?
 *
 *   vendor/bin/pest workbench/bench/FinaleProbe.php
 *
 * Re-seeds the W112 talk (TalkFeedBench.php) under four consumer-level
 * escapes that need NO package change, and prints the final minute of
 * ->summary() and ->live() under each. Measurement only.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

uses(TestCase::class);

/** Option C: a strategy that hands one verb no candidate hash at all. */
class FinaleOptOutStrategy extends MultiAxisStrategy
{
    public function hashes(Activity $activity): array
    {
        return $activity->verb === 'replay.played' ? [] : parent::hashes($activity);
    }
}

function finale_grammar(): void
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
 * A reduced W112 seed: the beats that matter for the finale, same timings.
 *
 * @return array{talk: Customer, speaker: User, start: Carbon, end: Carbon}
 */
function finale_seed(): array
{
    finale_grammar();

    $end = now();
    $start = $end->copy()->subMinutes(40);

    $waterloo = Customer::create(['name' => 'Waterloo']);
    $talk = Customer::create(['name' => 'London']);
    $speaker = User::create(['name' => 'Jasper', 'email' => 'speaker@talk.test']);
    $slides = collect(range(1, 41))->map(fn ($i) => Delivery::create(['tracking_number' => "Slide {$i}"]));
    $attendees = collect(range(1, 12))->map(fn ($i) => User::create(['name' => "Attendee {$i}", 'email' => "a{$i}@talk.test"]));

    $publish = fn (string $verb, User $actor, Carbon $at) => Storyfeed::activity($verb)->actor($actor)->publishedAt($at);

    $publish('talk.started', $speaker, $start)->target($talk)->context($talk)->publish();

    foreach (range(1, 40) as $i) {
        $publish('slide.advanced', $speaker, $start->copy()->addSeconds((int) round($i * 57.5)))
            ->target($talk)->context($talk)->origin($slides[$i - 1])->result($slides[$i])->publish();
    }

    foreach ($attendees as $n => $attendee) {
        $publish('audience.joined', $attendee, $start->copy()->addSeconds(5 + $n * 10))->target($talk)->context($talk)->publish();
    }

    // Sally-style repeat that every existing consumer relies on: one actor
    // uploads three distinct photos (object set, distinct ids) — must still
    // collapse as "×3" under any escape worth recommending.
    foreach (range(1, 3) as $p) {
        $publish('photo.uploaded', $attendees[0], $start->copy()->addMinutes(10 + $p))
            ->object($slides[$p + 30])->target($talk)->context($talk)->publish();
    }

    $publish('replay.played', $speaker, $end->copy()->subSeconds(50))->object($waterloo)->target($talk)->context($talk)->publish();
    $publish('replay.played', $speaker, $end->copy()->subSeconds(8))->object($talk)->target($talk)->context($talk)->publish();
    $publish('talk.ended', $speaker, $end)->target($talk)->context($talk)->publish();

    return compact('talk', 'speaker', 'start', 'end');
}

/** @param array<string, mixed> $item */
function finale_headline(array $item): string
{
    if (($item['headline'] ?? null) !== null) {
        return (string) $item['headline'];
    }
    $template = $item['headline_template'] ?? null;
    if ($template === null) {
        return '(null headline)';
    }
    $label = fn ($e) => is_array($e) ? ($e['label'] ?? '?') : '?';
    $tokens = [':count' => (string) ($item['count'] ?? 1)];
    foreach (['actor', 'object', 'target', 'context', 'origin', 'result'] as $role) {
        $tokens[":{$role}"] = $label($item[$role] ?? null);
        $list = array_map($label, $item['exemplars']["{$role}s"] ?? []);
        $distinct = $item['distinct']["{$role}s"] ?? count($list);
        $others = $distinct - count($list);
        $tokens[":{$role}s"] = implode(', ', $list).($others > 0 ? " and {$others} others" : '');
    }
    uksort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));

    return strtr($template, $tokens);
}

/** @return array<int, array<string, mixed>> */
function finale_read(string $mode, Customer $talk, ?Closure $query = null): array
{
    $items = [];
    $cursor = null;
    do {
        $feed = Storyfeed::feed()->involving($talk)->limit(100)->cursor($cursor)->{$mode}();
        if ($query) {
            $feed->query($query);
        }
        $page = $feed->get();
        $items = [...$items, ...$page->items()];
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    return $items;
}

/** @param array<int, array<string, mixed>> $items */
function finale_print(string $label, array $items): void
{
    fwrite(STDERR, "\n  -- {$label}: ".count($items)." rows --\n");
    foreach ($items as $item) {
        $time = substr((string) $item['published_at'], 11, 8);
        fwrite(STDERR, $item['kind'] === 'group'
            ? sprintf("  %s  [%-7s ×%-3d] %s\n", $time, $item['axis'], $item['count'], finale_headline($item))
            : sprintf("  %s  [%-16s] %s\n", $time, $item['verb'], finale_headline($item)));
    }
}

/**
 * @param  array{talk: Customer, speaker: User, start: Carbon, end: Carbon}  $fx
 * @return array<string, array<int, array<string, mixed>>> mode => replay.played rows
 */
function finale_report(string $title, array $fx): array
{
    Artisan::call('storyfeed:curate');

    $stamps = DB::table('feed_groupings')->join('feed_activities', 'feed_activities.id', '=', 'feed_groupings.activity_id')
        ->whereIn('verb', ['replay.played', 'photo.uploaded', 'slide.advanced'])
        ->selectRaw('verb, bucket, winner, count(*) as n')->groupBy('verb', 'bucket', 'winner')->orderBy('verb')->orderBy('bucket')->get();

    fwrite(STDERR, "\n==== {$title} ====\n  stamps (replay/photo/slide):\n");
    foreach ($stamps as $s) {
        fwrite(STDERR, sprintf("    %-16s %-9s winner=%-5s n=%d\n", $s->verb, $s->bucket, var_export((bool) $s->winner, true), $s->n));
    }

    $out = [];

    foreach (['summary', 'live'] as $mode) {
        $rows = finale_read($mode, $fx['talk']);
        $keep = array_values(array_filter($rows, fn ($i) => in_array($i['verb'], ['replay.played', 'photo.uploaded', 'slide.advanced', 'talk.ended'], true)));
        finale_print("->{$mode}() rows for replay/photo/slide/ended", $keep);
        $out[$mode] = array_values(array_filter($keep, fn ($i) => $i['verb'] === 'replay.played'));
        $out["{$mode}.photos"] = array_values(array_filter($keep, fn ($i) => $i['verb'] === 'photo.uploaded'));
    }

    return $out;
}

it('A. shipped defaults — the baseline', function () {
    $rows = finale_report('A. shipped defaults', finale_seed());

    // W112's diagnosis, reproduced: one repeat ×2 row in both modes.
    expect($rows['summary'])->toHaveCount(1)->and($rows['summary'][0]['kind'])->toBe('group')
        ->and($rows['live'])->toHaveCount(1)->and($rows['live'][0]['axis'])->toBe('repeat');
});

it('B. stitched read: ->summary() before the finale, ->log() from it', function () {
    $fx = finale_seed();
    Artisan::call('storyfeed:curate');
    $cut = $fx['end']->copy()->subSeconds(60);

    $before = finale_read('summary', $fx['talk'], fn ($q) => $q->where('published_at', '<', $cut));
    $after = finale_read('log', $fx['talk'], fn ($q) => $q->where('published_at', '>=', $cut));

    fwrite(STDERR, "\n==== B. stitched read (cut at {$cut->toTimeString()}) ====\n");
    finale_print('log  >= cut', $after);
    finale_print('summary < cut (head 4)', array_slice($before, 0, 4));

    // Does the cut leak? A repeat cluster straddling the cut would show the
    // pre-cut members as a group AND the post-cut members as solos — fine
    // for the finale, but say so.
    expect(count($after))->toBe(3);
});

it('C. a strategy that gives replay.played no hash (consumer subclass, config key)', function () {
    config(['storyfeed.grouping.strategy' => FinaleOptOutStrategy::class]);
    $rows = finale_report('C. opt-out strategy', finale_seed());

    // Doctor must stay quiet: Ungrouped re-runs the strategy, and a verb the
    // strategy declines is not an ungrouped import.
    Artisan::call('storyfeed:doctor');
    $doctor = Artisan::output();
    fwrite(STDERR, "\n  -- doctor (grouping lines) --\n".implode('', array_filter(explode("\n", $doctor), fn ($l) => str_contains(strtolower($l), 'group') || str_contains(strtolower($l), 'curat'))));

    // Two solo rows in both modes; Sally's three photos still collapse.
    expect($rows['summary'])->toHaveCount(2)->and($rows['live'])->toHaveCount(2)
        ->and($rows['summary.photos'])->toHaveCount(1)->and($rows['summary.photos'][0]['count'])->toBe(3);
});

it('D. repeat re-registered as a closure recipe that skips replay.played', function () {
    Storyfeed::axes([
        Axis::make('repeat')
            ->key(fn (Activity $a) => $a->verb === 'replay.played' ? null : implode(':', [
                $a->actor_type, $a->actor_id, $a->verb, $a->object_type, $a->target_type, $a->target_id, $a->published_at->toDateString(),
            ]))
            ->pins(':actor', ':target', ':verb')
            ->fallback(),
    ]);
    $rows = finale_report('D. closure repeat skipping the verb', finale_seed());

    // DEFECT, filed separately: with no applicable fallback, decide() stamps
    // the first candidate (actors) as winner without checking eligibility,
    // and summary renders an actors ×2 group with ONE distinct actor.
    expect($rows['summary'])->toHaveCount(1)->and($rows['summary'][0]['axis'])->toBe('actors')
        ->and($rows['live'])->toHaveCount(2);
});

it('E. repeat re-registered with the object id pinned (aa:aid:v:oa:oid:ta:tid:d)', function () {
    Storyfeed::axes([Axis::make('repeat')->key('aa:aid:v:oa:oid:ta:tid:d')->fallback()]);
    $rows = finale_report('E. repeat pins object id', finale_seed());

    // Fixes the finale, breaks the canonical repeat: three photos, three rows.
    expect($rows['summary'])->toHaveCount(2)->and($rows['summary.photos'])->toHaveCount(3);
});
