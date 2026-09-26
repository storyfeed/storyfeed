<?php

namespace Storyfeed\BodyMigrations;

use Illuminate\Database\Migrations\MigrationCreator;

/** Reuse Laravel's naming, collision handling, anonymous stubs and creation hooks. */
final class BodyMigrationCreator extends MigrationCreator
{
    protected function getStub($table, $create): string
    {
        return $this->files->get(dirname(__DIR__, 2).'/stubs/body-migration.stub');
    }
}
