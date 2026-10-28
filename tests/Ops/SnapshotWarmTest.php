<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\User;

/*
 * #225: `optimize` runs at build time on hosts such as Laravel Cloud, before
 * migrations, so the snapshot pass must not join it. It follows `migrate`.
 */

function fakeSnapshotPass(?Throwable $throws = null): object
{
    $passes = new class
    {
        public int $count = 0;
    };

    app(Kernel::class)->registerCommand(new class($passes, $throws) extends Command
    {
        protected $signature = 'storyfeed:cache-snapshots';

        public function __construct(private object $passes, private ?Throwable $throws)
        {
            parent::__construct();
        }

        public function handle(): void
        {
            $this->passes->count++;

            if ($this->throws) {
                throw $this->throws;
            }
        }
    });

    return $passes;
}

it('keeps every database read out of optimize', function () {
    expect(ServiceProvider::$optimizeCommands)
        ->toHaveKey('storyfeed', 'storyfeed:cache')
        ->not->toContain('storyfeed:cache-snapshots');
});

it('compiles snapshots after migrate, and when there was nothing to migrate', function () {
    $passes = fakeSnapshotPass();

    event(new MigrationsEnded('up'));
    event(new NoPendingMigrations('up'));

    expect($passes->count)->toBe(2);
});

it('does not compile after a rollback or a pretend run', function () {
    $passes = fakeSnapshotPass();

    event(new MigrationsEnded('down'));
    event(new NoPendingMigrations('down'));
    event(new MigrationsEnded('up', ['pretend' => true]));

    expect($passes->count)->toBe(0);
});

it('warns and carries on when the pass fails after migrate', function () {
    Log::spy();
    $passes = fakeSnapshotPass(new RuntimeException('column feed_orders.deleted_at does not exist'));

    event(new MigrationsEnded('up'));

    expect($passes->count)->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

it('compiles snapshots when migrate runs', function () {
    $passes = fakeSnapshotPass();

    $this->artisan('migrate')->assertSuccessful();

    expect($passes->count)->toBe(1);
});

it('warns and skips when an app model scope names a column not migrated yet', function () {
    Storyfeed::activity()->actor($user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']))
        ->verb('note', $user)
        ->publish();

    User::addGlobalScope('archived', fn ($query) => $query->whereNull('users.archived_at'));

    $this->artisan('storyfeed:cache-snapshots')
        ->expectsOutputToContain('Skipped compiling snapshots')
        ->assertSuccessful();

    User::clearBootedModels();
});
