<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Expression as QueryExpression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/** App references share storage with the package's numeric party/tombstone keys. */
class MorphKeyType
{
    public static function nullableMorphs(Blueprint $table, string $name, ?string $driver = null): void
    {
        $table->string($name.'_type')->nullable();
        self::id($table, $name.'_id', $driver)->nullable();
        $table->index([$name.'_type', $name.'_id']);
    }

    public static function id(Blueprint $table, string $name, ?string $driver = null): ColumnDefinition
    {
        $driver ??= Schema::getConnection()->getDriverName();

        // Laravel's SQL Server string type is nvarchar; app ids are ASCII.
        if ($driver === 'sqlsrv') {
            return $table->rawColumn($name, 'varchar(36)')->collation('Latin1_General_100_BIN2');
        }

        $column = $table->string($name, 36);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    /**
     * Cast only the package key: the indexed app-reference column stays bare.
     */
    public static function packageKey(Grammar $grammar, string $column): Expression
    {
        $wrapped = $grammar->wrap($column);
        if ($grammar instanceof MySqlGrammar) {
            return new QueryExpression("cast({$wrapped} as char(36) character set ascii) collate ascii_bin");
        }

        return new QueryExpression("cast({$wrapped} as varchar(36))");
    }
}
