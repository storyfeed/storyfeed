<?php

use Storyfeed\ActivityContext;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

final class CountingPresenter extends NodePresenter
{
    /** @var array<string, array{calls: int, ns: int}> */
    public static array $stats = [];

    private static function time(string $name, Closure $run): mixed
    {
        $start = hrtime(true);
        try {
            return $run();
        } finally {
            self::$stats[$name] ??= ['calls' => 0, 'ns' => 0];
            self::$stats[$name]['calls']++;
            self::$stats[$name]['ns'] += hrtime(true) - $start;
        }
    }

    public function activityNode(Activity $activity): array
    {
        return self::time('activityNode', fn () => parent::activityNode($activity));
    }

    protected function headline(Activity $activity): array
    {
        return self::time('headline', fn () => parent::headline($activity));
    }

    protected function render(string|Closure|null $entry, Activity $activity, ?string $type = null): array
    {
        return self::time('render', fn () => parent::render($entry, $activity, $type));
    }

    protected function entity(?string $type, int|string|null $id, ?Snapshot $snapshot): ?array
    {
        return self::time('entity', fn () => parent::entity($type, $id, $snapshot));
    }

    protected function activityEntities(Activity $activity): array
    {
        return self::time('activityEntities', fn () => parent::activityEntities($activity));
    }
}

it('counts member presentation on a photo-heavy page', function (bool $closure) {
    if ($closure) {
        Story::for(Delivery::class)->verb('photo')->headline(fn (ActivityContext $a) => ':actor added a photo to :object');
    }
    app()->bind(NodePresenter::class, CountingPresenter::class);

    $users = collect(range(1, 5))->map(fn (int $i) => User::create(['name' => "U{$i}", 'email' => "u{$i}@example.test"]));
    $customer = Customer::create(['name' => 'Record']);
    foreach (range(1, 2) as $g) {
        $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => "T{$g}"]);
        foreach (range(1, 50) as $m) {
            Storyfeed::activity('photo', $delivery)->actor($users[$m % 5])->target($customer)
                ->publishedAt(now()->subHours(3 - $g)->addSeconds($m))->publish();
        }
    }
    foreach (range(1, 6) as $s) {
        Storyfeed::activity('review', $customer)->actor($users[$s % 5])->publishedAt(now()->addMinutes($s))->publish();
    }

    $read = fn () => Storyfeed::feed()->limit(8)->inlineEntities(false)->page()->toJson();
    $read();
    $runs = 20;
    CountingPresenter::$stats = [];
    $start = hrtime(true);
    foreach (range(1, $runs) as $_) {
        $read();
    }
    $total = (hrtime(true) - $start) / 1e6 / $runs;
    $members = CountingPresenter::$stats['activityNode']['calls'] / $runs;
    $lines = [sprintf('closure=%s page=%.2fms members=%d per-member=%.3fms', $closure ? 'yes' : 'no', $total, $members, $total / max(1, $members))];
    foreach (CountingPresenter::$stats as $name => $s) {
        $lines[] = sprintf('  %-17s %5d calls/page %8.2fms/page %.4fms/call', $name, $s['calls'] / $runs, $s['ns'] / 1e6 / $runs, $s['ns'] / 1e6 / $s['calls']);
    }
    fwrite(STDERR, "\n".implode("\n", $lines)."\n");
    expect($members)->toBeGreaterThan(0);
})->with(['string grammar' => false, 'closure grammar' => true])->skip(fn () => ! env('STORYFEED_MEMBER_BENCH'), 'Opt in with STORYFEED_MEMBER_BENCH=1.');
