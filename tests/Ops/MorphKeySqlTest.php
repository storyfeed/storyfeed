<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Actions\PurgeActivities;
use Storyfeed\Diagnostics\Checks\RemovalVerbs;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Support\ActivityRoles;

it('casts package tombstone keys without casting indexed role columns', function (string $driver) {
    $connection = DB::connection();
    $previous = $connection->getQueryGrammar();
    $grammar = match ($driver) {
        'mysql' => new Grammars\MySqlGrammar($connection),
        'pgsql' => new Grammars\PostgresGrammar($connection),
        'sqlsrv' => new Grammars\SqlServerGrammar($connection),
        default => new Grammars\SQLiteGrammar($connection),
    };
    $connection->setQueryGrammar($grammar);
    try {
        $check = new class extends RemovalVerbs
        {
            public function query(): Builder
            {
                $query = $this->activities()->toBase();

                return $query->selectRaw($this->objectTypeOf($query).' as object_type');
            }
        };
        $cast = fn (string $column) => new Expression('cast('.$grammar->wrap($column).' as '.($driver === 'mysql' ? 'char(36) character set ascii' : 'varchar(36)').')'.($driver === 'mysql' ? ' collate ascii_bin' : ''));
        $legacy = Activity::query()->toBase();
        $legacy->leftJoin('feed_tombstones as former_objects', function (JoinClause $join) use ($cast) {
            $join->on($cast('former_objects.id'), '=', 'feed_activities.object_id')
                ->where('feed_activities.object_type', '=', FeedTombstone::MORPH_ALIAS);
        })->selectRaw('coalesce('.$grammar->wrap('former_objects.model_type').', '.$grammar->wrap('feed_activities.object_type').') as object_type');
        expect($check->query()->toSql())->toBe($legacy->toSql())
            ->and($check->query()->getBindings())->toBe($legacy->getBindings());

        $purge = new class extends PurgeActivities
        {
            public function query(): Builder
            {
                return $this->orphanedTombstones(['1']);
            }
        };
        $legacy = FeedTombstone::query()->toBase()->whereIn('feed_tombstones.id', ['1']);
        foreach (ActivityRoles::STORED as $role) {
            $legacy->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')
                ->from('feed_activities', 'referencing')
                ->where("referencing.{$role}_type", FeedTombstone::MORPH_ALIAS)
                ->whereColumn("referencing.{$role}_id", $cast('feed_tombstones.id')));
        }
        expect($purge->query()->toSql())->toBe($legacy->toSql())
            ->and($purge->query()->getBindings())->toBe($legacy->getBindings());
    } finally {
        $connection->setQueryGrammar($previous);
    }
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);

it('casts selected tombstone keys in retention inclusion and exclusion subqueries', function (string $driver) {
    $connection = DB::connection();
    $previous = $connection->getQueryGrammar();
    $grammar = match ($driver) {
        'mysql' => new Grammars\MySqlGrammar($connection),
        'pgsql' => new Grammars\PostgresGrammar($connection),
        'sqlsrv' => new Grammars\SqlServerGrammar($connection),
        default => new Grammars\SQLiteGrammar($connection),
    };
    $connection->setQueryGrammar($grammar);
    try {
        $query = (new PruneActivities)->expired([
            ['verb' => 'open', 'window' => 'P30D', 'types' => ['delivery'], 'except' => []],
            ['verb' => 'open', 'window' => 'P60D', 'types' => null, 'except' => ['delivery']],
        ]);
        $cast = 'cast('.$grammar->wrap('feed_tombstones.id').' as '.($driver === 'mysql' ? 'char(36) character set ascii' : 'varchar(36)').')'.($driver === 'mysql' ? ' collate ascii_bin' : '');
        expect(substr_count($query->toSql(), 'select '.$cast))->toBe(2)
            ->and($query->toSql())->toContain($grammar->wrap('object_id').' in (select '.$cast)
            ->toContain($grammar->wrap('object_id').' not in (select '.$cast);
    } finally {
        $connection->setQueryGrammar($previous);
    }
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);
