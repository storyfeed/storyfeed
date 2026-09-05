<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The `entities` check (todo 439): a model that fills a feed role and cannot
 * be resolved, named row by row with the reason. The direction `surface`
 * never checked — and the auth model first, because it is the one model the
 * package wires up without the integrator doing anything.
 */

/** The consumer's case: a host User that never heard of Feedable. */
class PlainUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

/** Resolves, is a model, is not Feedable. */
class PlainCustomer extends Model
{
    protected $table = 'customers';

    protected $guarded = [];
}

function insertRow(string $role, ?string $type, int|string|null $id, string $publishedAt = '2024-03-01 09:00:00'): int
{
    return (int) DB::table('feed_activities')->insertGetId([
        'uid' => (string) Str::uuid(),
        'verb' => 'doctrine_refreshed',
        "{$role}_type" => $type,
        "{$role}_id" => $id,
        'published_at' => $publishedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('says nothing on an app whose entities all resolve', function () {
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $customer = Customer::create(['name' => 'Concur']);

    Storyfeed::activity()
        ->actor($user)
        ->verb('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->for($customer)
        ->publish();

    $report = Storyfeed::doctor(['entities']);

    expect($report->all())->toBeEmpty()
        ->and($report->isHealthy())->toBeTrue();
});

it('warns about an authentication model without Feedable before anything has published', function () {
    config()->set('auth.providers.users.model', PlainUser::class);

    $report = Storyfeed::doctor(['entities']);
    $finding = $report->withCode('entities.auth_model')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject['model'])->toBe(PlainUser::class)
        ->and($finding->message)->toContain('fills the actor role from the authenticated user automatically')
        ->and($report->isHealthy())->toBeFalse();
});

it('leaves the authentication model alone when an actor resolver is configured', function () {
    config()->set('auth.providers.users.model', PlainUser::class);
    config()->set('storyfeed.actor_resolver', 'App\\Feed\\ResolveActor');

    expect(Storyfeed::doctor(['entities'])->has('entities.auth_model'))->toBeFalse();
});

it('names a role filled by a model that does not implement Feedable', function () {
    Relation::morphMap(['plain' => PlainCustomer::class]);

    $newest = insertRow('actor', 'plain', 1, '2024-03-02 09:00:00');
    $older = insertRow('actor', 'plain', 1);

    $finding = Storyfeed::doctor(['entities'])->withCode('entities.unfeedable')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toMatchArray([
            'role' => 'actor',
            'type' => 'plain',
            'class' => PlainCustomer::class,
            'activities' => 2,
            'examples' => "activity #{$newest}, activity #{$older}",
        ])
        ->and($finding->message)->toContain('does not implement Feedable')
        ->and($finding->message)->toContain('use InteractsWithFeed');
});

it('names an alias that resolves to no class at all', function () {
    $id = insertRow('object', 'ghost', 7);

    $finding = Storyfeed::doctor(['entities'])->withCode('entities.unresolvable')->sole();

    expect($finding->subject)->toMatchArray([
        'role' => 'object',
        'type' => 'ghost',
        'class' => null,
        'activities' => 1,
        'examples' => "activity #{$id}",
    ])->and($finding->message)->toContain('no morph map entry');
});

it('names a Feedable row that is gone, by type and id, with activities to look at', function () {
    $id = insertRow('actor', 'user', 999);

    $finding = Storyfeed::doctor(['entities'])->withCode('entities.missing')->sole();

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toMatchArray([
            'role' => 'actor',
            'type' => 'user',
            'class' => User::class,
            'missing' => 1,
            'ids' => '999',
            'examples' => "activity #{$id}",
        ])
        ->and($finding->message)->toContain('`user#999`');
});

it('reports a soft-deleted row the way the trickle would see it: unresolvable', function () {
    $customer = Customer::create(['name' => 'Gone']);

    insertRow('target', 'customer', $customer->id);
    $customer->delete();

    $finding = Storyfeed::doctor(['entities'])->withCode('entities.missing')->sole();

    expect($finding->subject['ids'])->toBe((string) $customer->id)
        ->and($finding->message)->toContain('hidden by a global scope');
});

it('does not name rows the trickle simply has not reached yet', function () {
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);

    // Present, uncached: that is `backlog`, not this.
    insertRow('actor', 'user', $user->id);

    expect(Storyfeed::doctor(['entities'])->all())->toBeEmpty();
});

it('reports each role and alias once, however many rows carry it', function () {
    Relation::morphMap(['plain' => PlainCustomer::class]);

    foreach (range(1, 5) as $i) {
        insertRow('actor', 'plain', $i);
        insertRow('object', 'plain', $i);
    }

    $findings = Storyfeed::doctor(['entities'])->withCode('entities.unfeedable');

    expect($findings)->toHaveCount(2)
        ->and($findings->pluck('subject.role')->all())->toBe(['actor', 'object'])
        ->and($findings->first()->subject['activities'])->toBe(5);
});

it('degrades when the activities table is not there', function () {
    config()->set('auth.providers.users.model', PlainUser::class);

    Schema::drop(config('storyfeed.tables.activities'));

    $report = Storyfeed::doctor(['entities']);

    // The auth model needs no table, so it is still reported; nothing throws.
    expect($report->has('doctor.check_failed'))->toBeFalse()
        ->and($report->has('entities.auth_model'))->toBeTrue()
        ->and($report->all())->toHaveCount(1);
});
