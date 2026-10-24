<?php

use Illuminate\Http\Request;
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

it('resolves the context from a callback when storyfeed.context names no parameter', function () {
    $user = User::create(['name' => 'Current workspace', 'email' => 'current@example.test']);
    Storyfeed::resolveContextUsing(fn (Request $request) => User::find($request->header('X-Workspace')));
    Route::get('/scope-resolved', fn () => ['context' => Storyfeed::activity('resolved-probe')->publish()->context_id])
        ->middleware('storyfeed.context');

    $this->withHeader('X-Workspace', (string) $user->id)->get('/scope-resolved')->assertOk()->assertJson(['context' => $user->id]);
    expect(Storyfeed::activity('outside')->publish()->context_id)->toBeNull();
});

it('runs without a context when the resolver returns null', function () {
    Storyfeed::resolveContextUsing(fn () => null);
    Route::get('/scope-unresolved', fn () => ['context' => Storyfeed::activity('resolved-probe')->publish()->context_id])
        ->middleware('storyfeed.context');

    $this->get('/scope-unresolved')->assertOk()->assertJson(['context' => null]);
});

it('names both fixes when there is no parameter and no resolver', function () {
    Route::get('/scope-unconfigured', fn () => 'never')->middleware('storyfeed.context');
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/scope-unconfigured'))->toThrow(InvalidArgumentException::class,
        'name a route parameter (`storyfeed.context:workspace`) or register a resolver with Storyfeed::resolveContextUsing()');
});
