<?php

namespace Storyfeed;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StoryfeedManager
{
    protected ?Closure $actorResolver = null;

    /**
     * Begin composing an activity.
     */
    public function activity(...$args): PendingActivity
    {
        return PendingActivity::make(...$args);
    }

    /**
     * Override how the default actor is resolved at publish time.
     */
    public function resolveActorUsing(Closure $resolver): void
    {
        $this->actorResolver = $resolver;
    }

    /**
     * Resolve the default actor for activities published without one.
     * Returns null for anonymous/system activities.
     */
    public function resolveActor(): ?Model
    {
        if ($this->actorResolver) {
            return ($this->actorResolver)();
        }

        if ($resolver = config('storyfeed.actor_resolver')) {
            return app($resolver)();
        }

        return Auth::user();
    }
}
