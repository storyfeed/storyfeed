<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MorphKeyType;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\SurfaceScanner;

/** String model keys cannot be stored in numeric app-reference columns. */
class MorphKeys extends Check
{
    public function __construct(
        protected Feedables $feedables,
        protected SurfaceScanner $scanner,
    ) {}

    public function name(): string
    {
        return 'morph_keys';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $mode = MorphKeyType::mode();
        $numeric = [];
        $columns = [
            'activities' => array_map(fn ($role) => $role.'_id', ActivityRoles::STORED),
            'batches' => ['actor_id'],
            'snapshots' => ['model_id'],
            'participants' => ['entity_id'],
            'batch_locks' => ['actor_id'],
            'tombstones' => ['model_id'],
        ];

        foreach ($columns as $table => $names) {
            if (! $this->hasTable($table)) {
                continue;
            }

            foreach ($names as $name) {
                if (Schema::hasColumn($this->table($table), $name)
                    && str_contains(Schema::getColumnType($this->table($table), $name), 'int')) {
                    $numeric[] = $this->table($table).'.'.$name;
                }
            }
        }

        if ($numeric === []) {
            return;
        }

        $classes = [...$this->feedables->registered(), ...$this->scanner->scan()['feedable']];

        // Read stored aliases directly: even an incompatible schema must be diagnosable.
        if ($this->hasTable('activities')) {
            foreach (ActivityRoles::STORED as $role) {
                if (! Schema::hasColumn($this->table('activities'), $role.'_type')) {
                    continue;
                }

                foreach ($this->activities()->toBase()->distinct()->pluck($role.'_type')->filter() as $alias) {
                    if ($class = MorphResolver::classFor($alias)) {
                        $classes[] = $class;
                    }
                }
            }
        }

        foreach (array_unique($classes) as $class) {
            if (! is_a($class, Model::class, true)) {
                continue;
            }

            $model = new $class;

            if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
                continue;
            }

            yield Finding::error(
                'morph_keys.incompatible',
                "[{$class}] uses string model keys, but ".implode(', ', $numeric).' store integers. '
                ."Set storyfeed.morph_key_type to 'string' and migrate the existing columns; "
                .'changing config alone does not change a migrated schema.',
                ['model' => $class, 'mode' => $mode, 'columns' => implode(', ', $numeric)],
            );
        }
    }
}
