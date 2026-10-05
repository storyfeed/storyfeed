<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Entity;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\FeedItem;
use Storyfeed\Support\MorphResolver;
use Throwable;

/**
 * Observed links, not a requirement to author them. Read the actual named
 * surface: inspecting a declaration would discard its subject scope and feed
 * identity. Neither an unscoped substitute nor a synthetic snapshot can tell
 * us what links the reader actually receives.
 */
class Links extends Check
{
    protected const SAMPLE = 30;

    public function name(): string
    {
        return 'links';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities') || ! $this->activities()->exists()) {
            return;
        }

        $inspectable = 0;

        foreach ($storyfeed->registeredFeeds() as $name => $definition) {
            try {
                // build() refuses missing subject arguments and preserves scope.
                // Materialize once: another items() call would run media again.
                $items = $definition->build()->limit(self::SAMPLE)->get()->collect();
            } catch (Throwable $e) {
                yield Finding::info(
                    'links.uninspectable',
                    "Feed `{$name}` could not be sampled for links (".$e::class
                    .'). No unscoped substitute was read, so nothing can be said about its links.',
                    ['feed' => $name, 'source' => $definition->source, 'exception' => $e::class],
                );

                continue;
            }

            $inspectable++;
            $observed = [];

            foreach ($items as $item) {
                // A pinned entity, sample and child may mirror the same entity.
                // Count it once per top-level item/role/type/id, retaining any URL.
                $entities = [];

                foreach ($this->entities($item) as [$role, $entity]) {
                    $type = $entity->type();
                    $class = $type === null ? null : MorphResolver::classFor($type);

                    if ($entity->isTombstone() || $class === null || ! is_a($class, Model::class, true)
                        || ! app(Feedables::class)->isFeedable($class)) {
                        continue;
                    }

                    $key = json_encode([$role, $type, $entity->id()], JSON_THROW_ON_ERROR);
                    $entities[$key] = [
                        'role' => $role,
                        'type' => $type,
                        'linked' => ($entities[$key]['linked'] ?? false) || $entity->url() !== null,
                    ];
                }

                foreach ($entities as $entity) {
                    $role = $entity['role'];
                    $type = $entity['type'];
                    $observed[$role][$type]['sampled'] = ($observed[$role][$type]['sampled'] ?? 0) + 1;
                    $observed[$role][$type]['linked'] = ($observed[$role][$type]['linked'] ?? false) || $entity['linked'];
                }
            }

            foreach ($observed as $role => $types) {
                foreach ($types as $type => $counts) {
                    if ($counts['linked']) {
                        continue;
                    }

                    yield Finding::info(
                        'links.missing',
                        "Feed `{$name}`: all {$counts['sampled']} inspected `{$type}` entities in the {$role} role "
                        .'had null URLs in a sample of '.$items->count().' top-level items (limit '.self::SAMPLE
                        .'). Unlinked entities are legitimate. This describes only the returned entities, including '
                        .'bounded group samples and children, not group totals or unsampled history; it does not '
                        .'prove that this type can never resolve a URL. Links may differ under another named feed.',
                        ['feed' => $name, 'role' => $role, 'type' => $type, 'sampled' => $counts['sampled'],
                            'items' => $items->count(), 'sample_limit' => self::SAMPLE],
                    );
                }
            }
        }

        if ($inspectable === 0) {
            yield Finding::info(
                'links.uninspectable',
                'Recorded traffic exists, but no registered named feed could be sampled. Links were not audited; '
                .'register a constructable named feed to inspect its actual scoped page. Subject feeds require a subject.',
                ['feed' => null, 'inspectable_feeds' => 0, 'sample_limit' => self::SAMPLE],
            );
        }
    }

    /** @return iterable<array{string, Entity}> */
    protected function entities(FeedItem $item): iterable
    {
        foreach (ActivityRoles::PAYLOAD as $role) {
            if (($entity = $item->entity($role)) !== null) {
                yield [$role, $entity];
            }

            foreach ($item->entities($role) as $entity) {
                yield [$role, $entity];
            }
        }

        foreach ($item->children()->concat($item->phrases()) as $child) {
            yield from $this->entities($child);
        }
    }
}
