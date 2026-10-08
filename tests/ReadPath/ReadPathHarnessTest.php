<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Storyfeed\Tests\Fixtures\ReadPathHistory;

it('preserves an automatic SQLite rollback during a seed', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Exercises SQLite RAISE(ROLLBACK).');
    }

    DB::unprepared("create trigger abort_seed before insert on feed_activities when new.id >= 1001 begin select raise(rollback, 'forced seed abort'); end");
    $cache = (int) DB::selectOne('pragma cache_size')->cache_size;

    try {
        expect(fn () => ReadPathHistory::seed(2000))->toThrow(QueryException::class, 'forced seed abort');
        expect(DB::transactionLevel())->toBe(0)
            ->and((int) DB::selectOne('pragma cache_size')->cache_size)->toBe($cache)
            ->and(DB::table('feed_activities')->count())->toBe(1000);
    } finally {
        DB::unprepared('drop trigger abort_seed');
    }
});

it('commits seed batches without retaining savepoints', function () {
    $levels = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$levels): void {
        if (preg_match('/^insert into ["`]?feed_(activities|groupings)/', $query)) {
            $levels[] = DB::transactionLevel();
        }
    });

    ReadPathHistory::seed(2000);

    expect(DB::table('feed_activities')->count())->toBe(2000)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(max($levels))->toBe(1);
});

it('rejects a wrapping transaction before seeding anything', function () {
    DB::transaction(function () {
        expect(fn () => ReadPathHistory::seed(2000))->toThrow(LogicException::class, 'must own its batch transactions');
        expect(DB::table('feed_activities')->count())->toBe(0)
            ->and(DB::table('users')->count())->toBe(0)
            ->and(DB::transactionLevel())->toBe(1);
    });
});

it('preserves the SQLite storage error when a seed exhausts its page limit', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Exercises SQLite page limits.');
    }

    $limit = (int) DB::selectOne('pragma max_page_count')->max_page_count;
    $pages = (int) DB::selectOne('pragma page_count')->page_count;
    $cache = (int) DB::selectOne('pragma cache_size')->cache_size;
    DB::statement('pragma max_page_count = '.($pages + 256));

    try {
        expect(fn () => ReadPathHistory::seed(1000))->toThrow(QueryException::class, 'database or disk is full');
        expect(DB::transactionLevel())->toBe(0)
            ->and((int) DB::selectOne('pragma cache_size')->cache_size)->toBe($cache);
    } finally {
        DB::statement('pragma max_page_count = '.$limit);
    }
});
