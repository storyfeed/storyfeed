<?php

namespace Storyfeed\Events\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Sheds loaded relations from every model the event carries — but only on
 * the way to a queue.
 *
 * A synchronous listener is handed the event object itself, never a
 * serialized copy, so `$event->activity->object` is still the model the
 * publisher passed and costs no query. A queued listener is different: its
 * CallQueuedListener serializes the event as-is, and an Activity fresh from
 * publish() carries the actor, object and target the builder associated,
 * each with every attribute PHP can see — `$hidden` governs toArray(), not
 * serialize(). Before this, one event put seven kilobytes, the actor's
 * remember_token, and in a real application its password hash, into the
 * jobs table or Redis, and into failed_jobs indefinitely if the listener
 * failed.
 *
 * Deliberately NOT SerializesModels. That trait re-fetches by key on the
 * worker, which is exactly wrong for ActivityDeleted — the row is gone —
 * and a ModelNotFoundException for an ActivityPublished row the trickle
 * pruned in between. The worker receives a detached copy of the row as it
 * was at dispatch; a relation read on that copy lazy-loads from the live
 * database.
 */
trait SerializesWithoutRelations
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $properties = [];

        // withoutRelations() clones, so the event held by a synchronous
        // listener in the same process keeps its graph intact.
        foreach (get_object_vars($this) as $name => $value) {
            $properties[$name] = $value instanceof Model ? $value->withoutRelations() : $value;
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __unserialize(array $properties): void
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
