<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Storyfeed\Exceptions\UndeclaredParty;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\User;

it('wraps a bound workbench route in both scopes and restores them afterwards', function () {
    require __DIR__.'/../../workbench/routes/scopes.php';
    $user = User::create(['name' => 'Route context', 'email' => 'route@example.test']);
    Storyfeed::parties(['Webhook']);
    $this->get('/scope-probe/'.$user->id)->assertOk()->assertJson(['context' => $user->id, 'actor' => 'Webhook']);
    expect(Activity::sole()->context_type)->toBe('user');
    $outside = Storyfeed::activity('outside')->publish();
    expect($outside->context_id)->toBeNull()->and($outside->actor_id)->toBeNull();
});

it('rejects missing and unbound route parameters clearly', function (string $path) {
    Route::get('/scope-missing/{user?}', fn () => 'never')->middleware('storyfeed.context:user');
    $this->withoutExceptionHandling();
    expect(fn () => $this->get($path))->toThrow(InvalidArgumentException::class,
        'storyfeed.context: route parameter [user] must be a bound Eloquent model.');
})->with(['/scope-missing', '/scope-missing/123']);

it('uses the declared party gate for actor middleware', function () {
    Route::get('/scope-party', fn () => 'never')->middleware('storyfeed.actor:Unknown');
    Storyfeed::parties(['Webhook']);
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/scope-party'))->toThrow(UndeclaredParty::class);
});

it('restores both scopes when a route throws', function () {
    Route::bind('user', fn () => User::create(['name' => 'Context', 'email' => 'throw@example.test']));
    Route::get('/scope-throw/{user}', function () {
        throw new RuntimeException('route failed');
    })->middleware([SubstituteBindings::class, 'storyfeed.context:user', 'storyfeed.actor:Webhook']);
    $this->withoutExceptionHandling();
    expect(fn () => $this->get('/scope-throw/1'))->toThrow(RuntimeException::class, 'route failed');
    $outside = Storyfeed::activity('outside')->publish();
    expect($outside->context_id)->toBeNull()->and($outside->actor_id)->toBeNull();
});
