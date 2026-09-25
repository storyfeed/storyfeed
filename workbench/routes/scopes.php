<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\User;

// Deliberately listed before bindings: the provider's priority must order it.
Route::middleware(['storyfeed.context:user', 'storyfeed.as:Webhook', SubstituteBindings::class])
    ->get('/scope-probe/{user}', function (User $user) {
        $activity = Storyfeed::activity('route-probe')->publish();

        return ['context' => $activity->context_id, 'actor' => $activity->actor?->getAttribute('name')];
    });
