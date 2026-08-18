<?php

namespace Storyfeed\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;

/**
 * The opt-in, read-only AS2.0 activity endpoint (config:
 * storyfeed.routes.enabled, default off). It exists in v1 primarily to make the
 * serialization layer testable end-to-end; it is the seed of a 2.x outbox.
 *
 * Content-negotiated: only AS2 media types are served. HTML/browser
 * requests get 406 rather than a surprise JSON blob — the app's own feed
 * UI is the payload contract's job, not this surface's.
 *
 * THERE IS NO COLLECTION ROUTE. `GET {prefix}/feed` existed and was removed at
 * v0.8.0-alpha.2: it served every published activity in the system, unscoped
 * and with no verb allowlist, and it could not be made safe without deciding
 * which named feed backs it — a design question, not a patch. Serializing a
 * collection is still supported (`Serialization\CollectionSerializer`), which
 * is the half that was never the problem. This route returns when a feed can
 * be named.
 *
 * A single activity is a different exposure and stays: it is addressed by an
 * unguessable ULID rather than enumerable, and `published()` gates it.
 */
class ActivityStreamsController
{
    protected const MEDIA_TYPE = 'application/activity+json';

    protected const ACCEPTED = [
        'application/activity+json',
        'application/ld+json',
        'application/json',
        '*/*',
    ];

    public function activity(Request $request, string $uid): JsonResponse
    {
        $this->negotiate($request);

        $model = config('storyfeed.models.activity', Activity::class);

        $activity = $model::query()
            ->published()
            ->where('uid', $uid)
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->firstOrFail();

        return $this->respond(app(ActivitySerializer::class)->activity($activity));
    }

    protected function negotiate(Request $request): void
    {
        abort_unless($request->accepts(self::ACCEPTED), 406);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function respond(array $document): JsonResponse
    {
        return response()
            ->json($document, options: JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', self::MEDIA_TYPE);
    }
}
