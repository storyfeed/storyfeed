<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\PurgeActivities;
use Storyfeed\Diagnostics\Checks\RemovalVerbs;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Support\ActivityRoles;

it('preserves the two legacy int mode queries byte for byte', function (string $driver) {
    config()->set('storyfeed.morph_key_type', 'int');
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
        $legacy = Activity::query()->toBase();
        $legacy->leftJoin('feed_tombstones as former_objects', function (JoinClause $join) {
            $join->on('former_objects.id', '=', 'feed_activities.object_id')
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
                ->whereColumn("referencing.{$role}_id", 'feed_tombstones.id'));
        }
        expect($purge->query()->toSql())->toBe($legacy->toSql())
            ->and($purge->query()->getBindings())->toBe($legacy->getBindings());
    } finally {
        $connection->setQueryGrammar($previous);
    }
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);
