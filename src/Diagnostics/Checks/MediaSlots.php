<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\Schema;
use Storyfeed\DeferredMedia;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\ModelHydrator;
use Storyfeed\Support\MorphResolver;
use Throwable;

/**
 * A body that names one of its model's `feedMedia()` pictures by slot, on a
 * model whose `feedMedia()` never sets that slot.
 *
 * `Image::make($this->feedMediaIcon())` stores the word `icon`, and the
 * renderer takes the picture from the entity's `media` at read time. If
 * `feedMedia()` sets no icon, the block draws its text and no picture, and
 * nothing anywhere says so ({@see DeferredMedia}: the slot is not checked
 * against the resolver when the body is written). That is knowable without
 * traffic: core owns the snapshot's `body` column, so this check reads the
 * forms that name a slot and asks the type's `feedMedia()` about them.
 *
 * ERROR, because the block draws blank where its author expected a picture,
 * on a surface that exists: the snapshot was written to be read.
 *
 * NEVER, NOT SOMETIMES. A resolver may set a slot for some rows and not
 * others (a dish with no photo yet), and that is a choice, not a defect. So
 * a slot is reported only when no probe of that type sets it: every sampled
 * row that names it, under every registered feed and none. A resolver that
 * throws on every probe is reported as unanswered, not as clean.
 */
class MediaSlots extends Check
{
    /** Snapshots read, newest first: what is arriving, not a total. */
    protected const SAMPLE = 200;

    /** Rows probed per type; `feedMedia()` may hydrate, so this stays small. */
    protected const PROBES = 10;

    public function name(): string
    {
        return 'media';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('snapshots') || ! Schema::hasColumn($this->table('snapshots'), 'body')) {
            return;
        }

        /** @var array<string, array<string, list<Snapshot>>> type => slot => rows naming it */
        $named = [];

        foreach ($this->snapshots() as $snapshot) {
            foreach ($snapshot->body ?? [] as $form) {
                $slot = DeferredMedia::tryFromPayload($form['image'] ?? null);

                if ($slot !== null && count($named[$snapshot->model_type][$slot->value] ?? []) < self::PROBES) {
                    $named[$snapshot->model_type][$slot->value][] = $snapshot;
                }
            }
        }

        $feeds = [null, ...array_keys($storyfeed->registeredFeeds())];

        foreach ($named as $alias => $slots) {
            $class = MorphResolver::classFor($alias);

            // An alias that resolves to nothing Feedable has no feedMedia()
            // to ask; `entities` and `references` report the alias itself.
            if ($class === null || ! app(Feedables::class)->isFeedable($class)) {
                continue;
            }

            foreach ($slots as $slot => $rows) {
                $answered = 0;
                $threw = null;

                foreach ($rows as $snapshot) {
                    foreach ($feeds as $feed) {
                        try {
                            $media = app(Feedables::class)->feedMedia($class, $this->context($snapshot, $feed));
                        } catch (Throwable $e) {
                            $threw = $e::class;

                            continue;
                        }

                        $answered++;

                        if ($this->sets($media, $slot)) {
                            continue 3;
                        }
                    }
                }

                $subject = [
                    'model' => $class,
                    'alias' => $alias,
                    'slot' => $slot,
                    'examples' => implode(', ', array_map(fn (Snapshot $row) => "snapshot #{$row->getKey()}", array_slice($rows, 0, 3))),
                ];

                if ($answered === 0) {
                    yield Finding::info(
                        'media.opaque',
                        "A body on `{$alias}` shows the `{$slot}` picture, and [{$class}]'s feedMedia() threw {$threw} "
                        .'on every probe, so whether it sets that slot cannot be said. The read path reports that '
                        .'exception and draws the block with no picture; fix the resolver and this check can answer.',
                        [...$subject, 'exception' => $threw],
                    );

                    continue;
                }

                yield Finding::error(
                    'media.unset_slot',
                    "A body on `{$alias}` shows its `{$slot}` picture ({$subject['examples']}), but [{$class}]'s "
                    ."feedMedia() never sets `{$slot}`, so the block draws its text and no picture. Set the slot in "
                    .'feedMedia(), or have the body store its own picture or name a slot the resolver sets.',
                    $subject,
                );
            }
        }
    }

    /**
     * The newest snapshots that carry a body.
     *
     * @return iterable<Snapshot>
     */
    protected function snapshots(): iterable
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);

        return $model::query()
            ->whereNotNull('body')
            ->latest('updated_at')
            ->limit(self::SAMPLE)
            ->get();
    }

    protected function context(Snapshot $snapshot, ?string $feed): FeedContext
    {
        return new FeedContext(
            type: $snapshot->model_type,
            key: $snapshot->model_id,
            label: $snapshot->label,
            data: $snapshot->data ?? [],
            feed: $feed,
            hydrator: new ModelHydrator,
            routeKey: $snapshot->meta['route_key'] ?? null,
        );
    }

    /** Whether the resolver's answer fills the slot a body stores as `$slot`. */
    protected function sets(?FeedMedia $media, string $slot): bool
    {
        if ($media === null) {
            return false;
        }

        return match ($slot) {
            'icon' => $media->icon !== null,
            'preview' => $media->preview !== null,
            'image' => $media->image !== null,
            default => isset($media->slots[substr($slot, strlen('slots.'))]),
        };
    }
}
