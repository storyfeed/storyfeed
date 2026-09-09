<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Actions\RebuildSnapshots;

/**
 * A role id is a morph id, and a morph id is not always an integer. Consumers
 * who publish the migration and swap nullableMorphs for its ULID/UUID form
 * store string role ids, and `storyfeed:rebuild` has to read them back as the
 * strings they are.
 *
 * The trap this pins: the query aliased `{role}_id as id`, which lands on
 * Activity's PRIMARY KEY. Eloquent casts the primary key to the model's
 * keyType, so a ULID actor arrived at resolve() as the integer 1 — asking for
 * the wrong model, and then stamping cached_actor_id onto every row whose
 * actor_id was 1.
 */
it('hands a string role id to the resolver unchanged, not cast to its key type', function () {
    $ulid = (string) Str::ulid();

    DB::table('feed_activities')->insert([
        'uid' => (string) Str::ulid(),
        'verb' => 'order.note',
        'actor_type' => 'user',
        'actor_id' => $ulid,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $action = new class extends RebuildSnapshots
    {
        /** @var list<int|string> */
        public array $seen = [];

        protected function resolve(string $type, int|string $id): ?Model
        {
            $this->seen[] = $id;

            return parent::resolve($type, $id);
        }
    };

    $action();

    expect($action->seen)->toContain($ulid);
});
