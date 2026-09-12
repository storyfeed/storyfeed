<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Models\Meta;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\SyncToken;

it('uses the configured meta model for tokens, history retention and its transaction', function () {
    config()->set('database.connections.custom_meta', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    Schema::connection('custom_meta')->create('consumer_meta', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->string('value');
        $table->timestamps();
    });

    $model = new class extends Meta
    {
        protected $connection = 'custom_meta';

        public function getTable(): string
        {
            return 'consumer_meta';
        }
    };
    config()->set('storyfeed.models.meta', $model::class);

    try {
        expect(SyncToken::current())->toBeNull();
        $token = SyncToken::bump();
        $transactionLevels = [];
        DB::connection('custom_meta')->beforeExecuting(function () use (&$transactionLevels) {
            $transactionLevels[] = DB::connection('custom_meta')->transactionLevel();
        });

        foreach (range(1, 23) as $count) {
            MaintenanceHistory::record('curate', ['processed' => $count, 'restamped' => 0, 'rehashed' => 0]);
        }

        expect(array_unique($transactionLevels))->toBe([1])
            ->and(array_column(MaintenanceHistory::recent('curate'), 'processed'))->toBe(range(4, 23))
            ->and(SyncToken::current())->toBe($token)
            ->and($model::query()->count())->toBe(21)
            ->and(Meta::query()->count())->toBe(0);
    } finally {
        DB::purge('custom_meta');
    }
});
