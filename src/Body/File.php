<?php

namespace Storyfeed\Body;

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;

/**
 * An artefact: what it is, how big, and where it lives.
 *
 *     public function toFeed(): FeedEntity
 *     {
 *         return FeedEntity::make($this->name, data: File::make(
 *             size: $this->bytes,
 *             mediaType: $this->mime,
 *         ));
 *     }
 *
 * The canonical case for the whole feature. "Sally uploaded archive.zip to
 * ProjectX" is the sentence; what a reader wants under it is how big it is and
 * a way to open it — and the model is the only thing that knows either.
 *
 * ## It stores no URL, deliberately
 *
 * The entity already carries one, regenerated LIVE at read time by core's
 * `Support\LinkResolver`. A URL copied in here would be a second copy that
 * ages: routes change, disks move, signed links expire, and the snapshot would
 * keep serving the one that was true when it was written. So a renderer hands
 * its view the entity's own link and this class stays out of it.
 *
 * That is also why `File` is not the remote-resource hazard `Link` and `Media`
 * are. It fetches nothing and embeds nothing — it labels a link the app already
 * decided to publish.
 *
 * ## Field names are AS2's where AS2 has one
 *
 * `mediaType`, not `mime`. `name`, not `filename`. Activity Streams 2.0 maps
 * this body type onto `attachment` → a `Document` with `url` and `mediaType`,
 * and matching its vocabulary now costs nothing and saves a translation layer
 * at the AS2 milestone.
 *
 * SIZE IS THE EXCEPTION: AS2 has no size property anywhere — `Link` carries
 * `mediaType`, `width`, `height`, `hreflang` and `rel`, and nothing for bytes —
 * so it is necessarily an extension term.
 *
 * Bytes are stored as bytes. "4.2 MB" is a rendering: the unit and the wording
 * follow the renderer and the locale rather than being frozen into a column by
 * whichever release wrote the row, so formatting lives with the renderer.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the body at read time, never write it back.
 */
class File implements FeedBody
{
    use HasPayload;

    final protected function __construct(
        private readonly ?string $name,
        private readonly ?int $size,
        private readonly ?string $mediaType,
    ) {}

    /**
     * @param  string|null  $name  only when it differs from the entity's label — a preview complements a headline
     * @param  int|null  $size  in bytes
     */
    public static function make(?int $size = null, ?string $mediaType = null, ?string $name = null): static
    {
        return new static(
            $name,
            $size !== null && $size >= 0 ? $size : null,
            $mediaType,
        );
    }

    /**
     * `Storyfeed/Body/File` — the VOCABULARY'S name, not a package's.
     *
     * A body outlives whichever library defined it ({@see FeedBody}), so the
     * name must not contain the library: this body type has already moved
     * packages once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix
     * would have moved with it. The name is a pure lookup key — no reflection,
     * no autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function name(): string
    {
        return 'Storyfeed/Body/File';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        return [
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : null,
            'size' => is_int($payload['size'] ?? null) ? $payload['size'] : null,
            'mediaType' => is_string($payload['mediaType'] ?? null) ? $payload['mediaType'] : null,
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, name: string|null, size: int|null, mediaType: string|null}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'name' => $this->name,
            'size' => $this->size,
            'mediaType' => $this->mediaType,
        ];
    }
}
