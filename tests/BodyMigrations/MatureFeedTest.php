<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Storyfeed\BodyMigrations\BodyGuard;
use Storyfeed\BodyMigrations\BodyRegistry;
use Storyfeed\BodyMigrations\RewriteBodies;
use Storyfeed\Diagnostics\Checks\Body;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SyncToken;
use Storyfeed\Tests\BodyMigrations\Fixtures\Attachment;
use Storyfeed\Tests\BodyMigrations\Fixtures\MatureFeed;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

beforeEach(function () {
    config()->set('cache.default', 'array');
    $this->migrationPath = __DIR__.'/../../build/body-migrations-'.bin2hex(random_bytes(8));
    MatureFeed::register(1);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->migrationPath);
});

function assertCurrentPage(array $items, int $version): void
{
    foreach ($items as $item) {
        $bodies = MatureFeed::bodies($item);
        expect($bodies)->toHaveCount(12);
        foreach ($bodies as $body) {
            expect($body['$body'])->not->toBe(BodyRegistry::UNREADABLE);
            if ($body['$body'] === Attachment::bodyType()) {
                expect($body['$v'])->toBe($version)->and($body)->toBe((new Attachment($version))->toPayload());
            } else {
                expect($body['$v'])->toBe(2);
            }
        }
    }
}

it('upgrades every page of a mature feed after one rename without writing any rows', function () {
    MatureFeed::seed(5100, unversioned: true);
    $before = DB::table('feed_activities')->pluck('data', 'id')->all();
    $snapshots = DB::table('feed_snapshots')->get()->toJson();
    MatureFeed::migration($this->migrationPath, 1);
    MatureFeed::register(2);
    $raw = Storyfeed::feed()->limit(50)->get()->toArray();
    expect($raw['items'][0]['data']['attachment'])->toHaveKey('size')->not->toHaveKey('$v');
    Storyfeed::upgradeBodies();
    $cursor = null;
    $seen = 0;
    do {
        $page = Storyfeed::feed()->limit(50)->cursor($cursor)->get()->toArray();
        assertCurrentPage($page['items'], 2);
        $seen += count($page['items']);
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect($seen)->toBe(5100)
        ->and(DB::table('feed_activities')->pluck('data', 'id')->all())->toBe($before)
        ->and(DB::table('feed_snapshots')->get()->toJson())->toBe($snapshots);
});

it('reads mixed v1 through v6 rows and resumes an interrupted eager rewrite with new writes', function () {
    MatureFeed::seed(5000, unversioned: true);
    for ($step = 1; $step <= 5; $step++) {
        MatureFeed::migration($this->migrationPath, $step);
        MatureFeed::register($step + 1);
        MatureFeed::seed(8, $step + 1);
    }
    Storyfeed::upgradeBodies();
    $rawVersions = DB::table('feed_activities')->orderByDesc('id')->limit(50)->pluck('data')->map(fn ($json) => json_decode($json, true)['attachment']['$v'] ?? 1)->unique()->sort()->values()->all();
    expect($rawVersions)->toBe([1, 2, 3, 4, 5, 6]);
    $wire = json_decode(json_encode(Storyfeed::feed()->limit(50)->get(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    assertCurrentPage($wire['items'], 6);
    $cursor = null;
    $seen = 0;
    do {
        $page = Storyfeed::feed()->limit(50)->cursor($cursor)->get()->toArray();
        assertCurrentPage($page['items'], 6);
        $seen += count($page['items']);
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect($seen)->toBe(5040);
    $rewrite = app(RewriteBodies::class);
    expect(fn () => $rewrite->run(500, function (int $chunks): void {
        if ($chunks === 5) {
            MatureFeed::seed(10, 6);
            throw new RuntimeException('Simulated worker termination after committed chunk');
        }
    }))->toThrow(RuntimeException::class, 'Simulated worker termination');
    expect(json_decode(DB::table('feed_activities')->where('id', 2500)->value('data'), true)['attachment']['$v'])->toBe(6)
        ->and(json_decode(DB::table('feed_activities')->where('id', 2501)->value('data'), true)['attachment'])->not->toHaveKey('$v');
    assertCurrentPage(Storyfeed::feed()->limit(50)->get()->items(), 6);
    $result = $rewrite->run(500);
    expect($result['changed'])->toBeGreaterThan(0);
    foreach ($rewrite->columns() as $table => $columns) {
        foreach (DB::table($table)->get($columns) as $row) {
            foreach ($columns as $column) {
                foreach (MatureFeed::bodies(json_decode($row->$column ?? 'null', true)) as $body) {
                    expect($body['$v'])->toBe($body['$body'] === Attachment::bodyType() ? 6 : 2);
                }
            }
        }
    }
    $party = Party::query()->firstOrFail();
    $party->name = 'Updated sender';
    $party->save();
    $refreshed = Snapshot::query()->where('model_type', $party->getMorphClass())->where('model_id', $party->id)->firstOrFail();
    foreach (MatureFeed::bodies($refreshed->data) as $body) {
        expect($body['$v'])->toBe($body['$body'] === Attachment::bodyType() ? 6 : 2);
    }
    $sync = SyncToken::current();
    expect($sync)->not->toBeNull();
    expect($rewrite->run()['changed'])->toBe(0)
        ->and(SyncToken::current())->toBe($sync);
    $this->artisan('storyfeed:migrate-bodies', ['--chunk' => 300])->assertSuccessful();
});

it('uses placeholders for forgotten migrations then restores the same mature feed on the next read', function () {
    MatureFeed::seed(5100, unversioned: true);
    $released = BodyGuard::snapshot(new Attachment(1));
    MatureFeed::register(2);
    expect(fn () => BodyGuard::assertCompatible($released, BodyGuard::snapshot(new Attachment(2))))
        ->toThrow(LogicException::class, 'removed [size], added [bytes]');
    Storyfeed::upgradeBodies();
    Log::spy();
    $before = DB::table('feed_activities')->pluck('data', 'id')->all();
    $cursor = null;
    $seen = 0;
    do {
        $page = Storyfeed::feed()->limit(50)->cursor($cursor)->get()->toArray();
        foreach ($page['items'] as $item) {
            $bodies = MatureFeed::bodies($item);
            expect($bodies)->toHaveCount(12);
            expect(array_filter($bodies, fn ($body) => $body['$body'] === BodyRegistry::UNREADABLE))->toHaveCount(4);
        }
        $seen += count($page['items']);
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect($seen)->toBe(5100);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => $message === "Acme/Attachment v1 rows don't match the current shape; add a body migration");
    $findings = iterator_to_array((new Body)->run(app(StoryfeedManager::class)), false);
    expect(array_values(array_filter($findings, fn ($f) => $f->code === 'body.unreadable')))->toHaveCount(1);
    MatureFeed::migration($this->migrationPath, 1);
    assertCurrentPage(Storyfeed::feed()->limit(50)->get()->items(), 2);
    expect(DB::table('feed_activities')->pluck('data', 'id')->all())->toBe($before);
});

it('isolates throwing migrations and future versions without persisting placeholders', function () {
    MatureFeed::seed(2);
    for ($step = 1; $step <= 5; $step++) {
        MatureFeed::migration($this->migrationPath, $step);
    }
    MatureFeed::register(6);
    $rows = DB::table('feed_activities')->orderBy('id')->get();
    $bad = json_decode($rows[0]->data, true);
    $bad['attachment']['mediaType'] = ['invalid'];
    DB::table('feed_activities')->where('id', $rows[0]->id)->update(['data' => json_encode($bad)]);
    $future = json_decode($rows[1]->data, true);
    $future['attachment']['$v'] = 99;
    DB::table('feed_activities')->where('id', $rows[1]->id)->update(['data' => json_encode($future)]);
    Storyfeed::upgradeBodies();
    $items = Storyfeed::feed()->get()->items();
    expect($items)->toHaveCount(2);
    foreach ($items as $item) {
        expect($item['data']['attachment']['$body'])->toBe(BodyRegistry::UNREADABLE)
            ->and($item['object']['body'][0]['$v'])->toBe(6);
    }
    app(RewriteBodies::class)->run();
    expect(json_decode(DB::table('feed_activities')->where('id', $rows[0]->id)->value('data'), true)['attachment'])->toBe($bad['attachment'])
        ->and(json_decode(DB::table('feed_activities')->where('id', $rows[1]->id)->value('data'), true)['attachment'])->toBe($future['attachment']);
});

it('recovers durable chunks after the operating system kills a rewrite worker', function () {
    if (DB::connection()->getDriverName() !== 'sqlite' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('SIGKILL fixture uses SQLite and POSIX.');
    }
    MatureFeed::seed(5100, unversioned: true);
    for ($step = 1; $step <= 5; $step++) {
        MatureFeed::migration($this->migrationPath, $step);
    }
    MatureFeed::register(6);
    $file = $this->migrationPath.'/feed.sqlite';
    DB::unprepared("VACUUM INTO '".str_replace("'", "''", $file)."'");
    $worker = new Process([PHP_BINARY, __DIR__.'/Fixtures/kill-worker.php', $file, $this->migrationPath]);
    try {
        $worker->run();
    } catch (ProcessSignaledException $exception) {
        expect($exception->getProcess()->getTermSignal())->toBe(9);
    }
    expect($worker->hasBeenSignaled())->toBeTrue()->and($worker->getTermSignal())->toBe(9);
    $previous = config('database.connections.testing');
    try {
        config()->set('database.connections.testing.database', $file);
        DB::purge('testing');
        expect(json_decode(DB::table('feed_activities')->where('id', 2500)->value('data'), true)['attachment']['$v'])->toBe(6)
            ->and(json_decode(DB::table('feed_activities')->where('id', 2501)->value('data'), true)['attachment'])->not->toHaveKey('$v');
        Storyfeed::upgradeBodies();
        assertCurrentPage(Storyfeed::feed()->limit(50)->get()->items(), 6);
        expect(app(RewriteBodies::class)->run()['changed'])->toBe(2603)
            ->and(app(RewriteBodies::class)->run()['changed'])->toBe(0);
    } finally {
        DB::purge('testing');
        config()->set('database.connections.testing', $previous);
    }
});
