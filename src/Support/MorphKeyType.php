<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Expression as QueryExpression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;

/** App references share storage with the package's numeric party/tombstone keys. */
class MorphKeyType
{
    public static function mode(): string
    {
        $mode = config('storyfeed.morph_key_type');

        if ($mode === null) {
            $mode = Builder::$defaultMorphKeyType === 'int' ? 'int' : 'string';
        }

        if (! in_array($mode, ['int', 'string'], true)) {
            throw new InvalidArgumentException("storyfeed.morph_key_type must be null, 'int', or 'string'.");
        }

        return $mode;
    }

    public static function nullableMorphs(Blueprint $table, string $name): void
    {
        if (self::mode() === 'int') {
            $table->nullableNumericMorphs($name);

            return;
        }

        $table->string($name.'_type')->nullable();
        self::id($table, $name.'_id')->nullable();
        $table->index([$name.'_type', $name.'_id']);
    }

    public static function id(Blueprint $table, string $name, bool $legacyString = false): ColumnDefinition
    {
        return self::mode() === 'string'
            ? $table->string($name, 36)
            : ($legacyString ? $table->string($name) : $table->unsignedBigInteger($name));
    }

    /**
     * Cast only the package key: the indexed app-reference column stays bare.
     */
    public static function packageKey(Grammar $grammar, string $column): string|Expression
    {
        if (self::mode() === 'int') {
            return $column;
        }

        $wrapped = $grammar->wrap($column);
        $type = $grammar instanceof MySqlGrammar ? 'char(36)' : 'varchar(36)';

        return new QueryExpression("cast({$wrapped} as {$type})");
    }
}
