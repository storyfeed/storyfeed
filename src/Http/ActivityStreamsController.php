<?php

namespace Storyfeed\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Serialization\CollectionSerializer;

/**
 * The opt-in, read-only AS2.0 endpoints (config: storyfeed.routes.enabled,
 * default off). They exist in v1 primarily to make the serialization layer
 * testable end-to-end; they are the seed of a 2.x outbox.
 *
 * Content-negotiated: only AS2 media types are served. HTML/browser
 * requests get 406 rather than a surprise JSON blob — the app's own feed
 * UI is the payload contract's job, not this surface's.
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

    public function feed(Request $request): JsonResponse
    {
        $this->negotiate($request);

        $document = app(CollectionSerializer::class)->feed(
            cursor: $request->query('cursor'),
            limit: min(100, max(1, (int) $request->query('limit', '30'))),
        );

        return $this->respond($document);
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
