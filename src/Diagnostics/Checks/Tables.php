<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

class Tables extends Check
{
    public function name(): string
    {
        return 'tables';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach (config('storyfeed.tables', []) as $key => $table) {
            if (! Schema::hasTable($table)) {
                yield Finding::error(
                    'tables.missing',
                    "Table `{$table}` ({$key}) does not exist — run the migrations.",
                    ['table' => $table, 'key' => $key],
                );
            }
        }
    }
}
