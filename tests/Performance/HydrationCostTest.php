<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Storyfeed\ActivityContext;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

if (! function_exists('hydrationCase')) {
    function hydrationCase(): Customer
    {
        Story::for(Delivery::class)->verb('photo')->headline(fn (ActivityContext $a) => ':actor added a photo to :object');
        $users = collect(range(1, 5))->map(fn (int $i) => User::create(['name' => "U{$i}", 'email' => "h{$i}@example.test"]));
        $case = Customer::create(['name' => 'Case']);
        $photo = Delivery::create(['customer_id' => $case->id, 'tracking_number' => 'P']);
        foreach (range(1, 50) as $m) {
            Storyfeed::activity('photo', $photo)->actor($users[$m % 5])->target($case)->publishedAt(now()->subHours(2)->addSeconds($m))->publish();
        }
        foreach (range(1, 25) as $s) {
            $delivery = Delivery::create(['customer_id' => $case->id, 'tracking_number' => "D{$s}"]);
            Storyfeed::activity(['ship', 'confirm', 'update'][$s % 3], $delivery)->actor($users[$s % 5])->target($case)->publishedAt(now()->subMinutes(60 - $s))->publish();
        }

        return $case;
    }
}

it('splits a case page read into hydration, casts, boot and queries', function () {
    $case = hydrationCase();
    $read = fn () => json_encode(Storyfeed::feed()->involving($case)->cursorPaginate(30));
    $read();

    $retrieved = [];
    Model::getEventDispatcher()->listen('eloquent.retrieved: *', function (string $event) use (&$retrieved) {
        $class = substr($event, strlen('eloquent.retrieved: '));
        $retrieved[$class] = ($retrieved[$class] ?? 0) + 1;
    });
    $queries = 0;
    $queryMs = 0.0;
    DB::listen(function ($q) use (&$queries, &$queryMs) {
        $queries++;
        $queryMs += $q->time;
    });

    if (env('STORYFEED_HYDRATION_BENCH') === 'wall') {
        $times = [];
        foreach (range(1, 300) as $_) {
            $t = hrtime(true);
            $read();
            $times[] = (hrtime(true) - $t) / 1e6;
        }
        sort($times);
        fwrite(STDERR, sprintf("\nwall p50 %.2fms p25 %.2fms\n", $times[150], $times[75]));
        expect($times)->not->toBeEmpty();

        return;
    }
    $runs = 40;
    $profiler = new ExcimerProfiler;
    $profiler->setPeriod(0.0002);
    $profiler->setEventType(EXCIMER_REAL);
    $start = hrtime(true);
    $profiler->start();
    foreach (range(1, $runs) as $_) {
        $read();
    }
    $profiler->stop();
    $wall = (hrtime(true) - $start) / 1e6 / $runs;

    $buckets = [
        'activity rows (Builder::get on Activity / hydrate)' => fn (array $f) => hasFrame($f, 'Illuminate\Database\Eloquent\Builder::getModels') && ! hasFrame($f, 'Illuminate\Database\Eloquent\Builder::eagerLoadRelations'),
        'related models (eager loads)' => fn (array $f) => hasFrame($f, 'Illuminate\Database\Eloquent\Builder::eagerLoadRelations'),
        'ModelHydrator' => fn (array $f) => hasFrame($f, 'Storyfeed\Support\ModelHydrator::'),
        'model boot / construct (newInstance, bootIfNotBooted, initializeTraits)' => fn (array $f) => hasFrame($f, '::bootIfNotBooted') || hasFrame($f, '::initializeTraits') || hasFrame($f, 'Model::__construct'),
        'casts (getCasts, castAttribute, transformModelValue)' => fn (array $f) => hasFrame($f, '::getCasts') || hasFrame($f, '::castAttribute') || hasFrame($f, '::transformModelValue') || hasFrame($f, '::asDateTime'),
        'attribute reads (getAttribute / __get)' => fn (array $f) => hasFrame($f, 'Model::getAttribute') || hasFrame($f, 'Model::__get'),
        'SQL (PDO)' => fn (array $f) => hasFrame($f, 'PDOStatement::execute') || hasFrame($f, 'PDO::prepare'),
        'NodePresenter (inclusive)' => fn (array $f) => hasFrame($f, 'Storyfeed\Payload\NodePresenter::'),
        'json_encode' => fn (array $f) => hasFrame($f, 'json_encode'),
    ];
    $counts = array_fill_keys(array_keys($buckets), 0);
    $total = 0;
    $callers = [];
    $leaves = [];
    foreach ($profiler->getLog() as $entry) {
        $frames = array_map(fn (array $f) => ($f['class'] ?? '').'::'.($f['function'] ?? $f['closure_line'] ?? ''), $entry->getTrace());
        $n = $entry->getEventCount();
        $total += $n;
        if (hasFrame($frames, 'Model::getAttribute') || hasFrame($frames, 'Model::__get') || hasFrame($frames, '::castAttribute')) {
            $caller = null;
            foreach ($frames as $i => $frame) {
                if (str_starts_with($frame, 'Storyfeed\\')) {
                    $caller = $frame;
                    break;
                }
            }
            $callers[$caller ?? '?'] = ($callers[$caller ?? '?'] ?? 0) + $n;
            $leaf = $frames[0];
            $leaves[$leaf] = ($leaves[$leaf] ?? 0) + $n;
        }
        foreach ($buckets as $name => $match) {
            if ($match($frames)) {
                $counts[$name] += $n;
            }
        }
    }

    $lines = [sprintf('cursorPaginate+json: %.2fms wall/read, %d samples, %d queries/read (%.2fms)', $wall, $total, $queries / $runs, $queryMs / $runs)];
    foreach ($counts as $name => $c) {
        $lines[] = sprintf('  %5.1f%%  %6.2fms  %s', 100 * $c / max(1, $total), $wall * $c / max(1, $total), $name);
    }
    arsort($callers);
    arsort($leaves);
    foreach (array_slice($callers, 0, 30, true) as $k => $c) {
        $lines[] = sprintf('  caller %5.1f%% %s', 100 * $c / $total, $k);
    }
    foreach (array_slice($leaves, 0, 12, true) as $k => $c) {
        $lines[] = sprintf('  leaf %5.1f%% %s', 100 * $c / $total, $k);
    }
    foreach ($retrieved as $class => $c) {
        $lines[] = sprintf('  retrieved %4d/read %s', $c / $runs, $class);
    }
    fwrite(STDERR, "\n".implode("\n", $lines)."\n");
    expect($total)->toBeGreaterThan(0);
})->skip(fn () => ! env('STORYFEED_HYDRATION_BENCH') || ! class_exists(ExcimerProfiler::class), 'Opt in with STORYFEED_HYDRATION_BENCH=1 and the excimer extension.');

/** @param list<string> $frames */
function hasFrame(array $frames, string $needle): bool
{
    foreach ($frames as $frame) {
        if (str_contains($frame, $needle)) {
            return true;
        }
    }

    return false;
}
