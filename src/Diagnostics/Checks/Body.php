<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Collection;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\FeedThread;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;

/**
 * The body types that are actually in the `data` column, and the two ways
 * one can be malformed without anything ever going wrong out loud.
 *
 * {@see FeedBody} is a spec core publishes and never implements: a body is
 * the app's value, at the app's key, drawn by a renderer core knows nothing
 * about. Core reads one here, and only here, because a spec nobody can see the
 * state of is a spec that drifts — this package's history is that the doctor is
 * where contract drift gets caught, months before a consumer reports it.
 *
 * ## What it will NOT report, and this is the design
 *
 * Two of the obvious candidates cannot be written without core learning a
 * vocabulary, so neither is here:
 *
 *   an unknown type token         core has no list of known names, on purpose.
 *                                 A "body this renderer never heard of"
 *                                 renders as nothing by rule 3 and is a fact
 *                                 about ONE renderer, not about the row. Core
 *                                 answering it would require a registry, and
 *                                 the registry is the thing {@see FeedBody}
 *                                 exists to avoid.
 *   a payload whose shape does    same wall, one level in: knowing that
 *   not match its version         version 2 of `Acme/Shipment` has a `carrier`
 *                                 key means knowing `Acme/Shipment`.
 *
 * What is left is everything a reader can see WITHOUT a vocabulary: which body
 * types are in the column, and whether the two reserved keys are usable. That
 * turns out to be most of the value, because both failures are silent.
 *
 * ## Silence means clean, and there is nothing to skip
 *
 * An app that records no bodies gets no findings, the way `hydration` is
 * silent where no resolver hydrates. It is not "skipped": the column was read
 * and there was nothing in it.
 *
 * ## INFO for the census, WARNING only where something silently disappears
 *
 * `hydration` is Info because hydrating is a legitimate choice and a report
 * that warns about a deliberate decision is the report people stop reading.
 * `entities` is Error because every one of those rows renders without a
 * label or a link. This check does neither, and the line it draws is whether
 * a reader is being shown something wrong:
 *
 * - which body types exist, and rows with no version at all, are FACTS. A
 *   missing `$v` is version 1 by definition ({@see FeedBody::VERSION}), so
 *   those rows read correctly today and will keep reading correctly forever.
 *   Info.
 * - a body type that declares version 2 on some rows and nothing on others,
 *   and a map that meant to be a body and cannot be dispatched, are
 *   Warnings: the
 *   first upgrades down the wrong path and renders plausible, wrong output;
 *   the second renders nothing at all, on every page, with no error anywhere.
 *
 * NOTHING HERE IS AN ERROR. A malformed body is fail-open by rule 3 — the
 * activity still renders, minus a preview — so what the reader sees is an
 * absence, not a sentence that reads wrong, and a diagnostic that failed the
 * build on it would contradict the rule it is checking. `version_ambiguous`
 * is the closest call: the output CAN be wrong, but only if the unversioned
 * rows were written by the newer version, and the check cannot know that.
 */
class Body extends Check
{
    /**
     * Rows read per table, newest first. A bound rather than a scan: this runs
     * on tables that grow forever, and the newest rows are the ones a writer
     * that has started misbehaving is putting there. Findings say so.
     */
    protected const SAMPLE = 200;

    /**
     * How deep the walk looks before it stops.
     *
     * A body sits ALONGSIDE the app's own keys, so finding one means walking
     * the map rather than reading a fixed key — the same walk, and deliberately
     * the same cap, as the Filament adapter's `Detail\Registry`. Bodies never
     * nest (rule 4), so anything deeper is an app's own data structure that
     * happens to be deep, not a body hiding.
     */
    protected const MAX_DEPTH = 4;

    /** Rows quoted per finding — enough to go and look at, never a listing. */
    protected const EXAMPLES = 3;

    /**
     * Keys inside `data` that core owns and reads itself.
     *
     * The walk steps over them. Each is a core envelope that carries a `$v` of
     * its own and no `$body` — `$thread` on every threaded activity — so a
     * naive walk would report each of those rows as a broken body. A new core envelope with a VERSION belongs here too; a
     * test holds the list to that.
     *
     * @var list<string>
     */
    protected const RESERVED = [FeedThread::KEY];

    public function name(): string
    {
        return 'body';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        /** @var array<string, array{activity: int, snapshot: int, versions: list<int>, unversioned: int, examples: list<string>}> */
        $types = [];

        /** @var array{count: int, examples: list<string>} */
        $untokenized = ['count' => 0, 'examples' => []];

        /** @var array{count: int, examples: list<string>, types: list<string>} */
        $malformed = ['count' => 0, 'examples' => [], 'types' => []];

        $capped = false;

        foreach ($this->sources() as $noun => $rows) {
            $capped = $capped || $rows->count() >= self::SAMPLE;

            foreach ($rows as $id => $data) {
                $where = "{$noun} #{$id}";

                foreach ($this->walk($data, 0) as $found) {
                    $token = $found[FeedBody::KEY] ?? null;
                    $version = $found[FeedBody::VERSION] ?? null;

                    if (is_string($token) && $token !== '') {
                        $type = $types[$token] ?? ['activity' => 0, 'snapshot' => 0, 'versions' => [], 'unversioned' => 0, 'examples' => []];

                        if ($noun === 'activity') {
                            $type['activity']++;
                        } else {
                            $type['snapshot']++;
                        }

                        $this->remember($type['examples'], $where);

                        if (is_int($version) && $version > 0) {
                            $type['versions'] = array_values(array_unique([...$type['versions'], $version]));
                            sort($type['versions']);
                        } else {
                            $type['unversioned']++;
                        }

                        $types[$token] = $type;

                        continue;
                    }

                    if ($token !== null) {
                        $malformed['count']++;
                        $malformed['types'] = array_values(array_unique([...$malformed['types'], get_debug_type($token)]));
                        $this->remember($malformed['examples'], $where);

                        continue;
                    }

                    $untokenized['count']++;
                    $this->remember($untokenized['examples'], $where);
                }
            }
        }

        $sampled = $capped
            ? ' Read from the '.self::SAMPLE.' most recent rows of each table, so this is what is arriving, not a total.'
            : '';

        foreach ($types as $token => $type) {
            yield from $this->bodyType($token, $type, $sampled);
        }

        if ($untokenized['count'] > 0) {
            $count = $untokenized['count'];

            yield Finding::warning(
                'body.untokenized',
                "{$count} ".str('map')->plural($count).' inside `data` '.($count === 1 ? 'carries' : 'carry')
                .' a `'.FeedBody::VERSION.'` but no `'.FeedBody::KEY.'` (e.g. '
                .implode(', ', $untokenized['examples']).'). A renderer finds a body by its NAME, so a versioned '
                .'map with no name is drawn by nobody: it renders as nothing, on every page it appears on, with no '
                .'error anywhere to say so. Name the body type '
                ."(`'".FeedBody::KEY."' => 'Acme/Shipment'`), or drop the `".FeedBody::VERSION
                .'` if the map was never meant to be a body.'.$sampled,
                ['maps' => $count, 'examples' => implode(', ', $untokenized['examples'])],
            );
        }

        if ($malformed['count'] > 0) {
            $count = $malformed['count'];
            $types = implode(', ', $malformed['types']);

            yield Finding::warning(
                'body.malformed_token',
                "{$count} ".str('map')->plural($count).' inside `data` '.($count === 1 ? 'has' : 'have')
                .' a `'.FeedBody::KEY."` that is not a string ({$types}) — e.g. "
                .implode(', ', $malformed['examples']).'. Dispatch is by name and a name is a string, so those '
                .'render as nothing. The body type\'s `bodyType()` is what belongs there, written into storage verbatim.'
                .$sampled,
                ['maps' => $count, 'types' => $types, 'examples' => implode(', ', $malformed['examples'])],
            );
        }
    }

    /**
     * One body type: what it is, where it is, and whether its versions add up.
     *
     * @param  array{activity: int, snapshot: int, versions: list<int>, unversioned: int, examples: list<string>}  $type
     * @return iterable<Finding>
     */
    protected function bodyType(string $token, array $type, string $sampled): iterable
    {
        $rows = $type['activity'] + $type['snapshot'];
        $where = implode(' and ', array_filter([
            $type['activity'] > 0 ? $type['activity'].' '.str('activity')->plural($type['activity']) : null,
            $type['snapshot'] > 0 ? $type['snapshot'].' '.str('snapshot')->plural($type['snapshot']) : null,
        ]));

        $versions = $type['versions'] === []
            ? 'no declared version'
            : 'version '.implode(', ', $type['versions']);

        $subject = [
            'body_type' => $token,
            'rows' => $rows,
            'activities' => $type['activity'],
            'snapshots' => $type['snapshot'],
            'versions' => implode(', ', $type['versions']),
            'unversioned' => $type['unversioned'],
            'examples' => implode(', ', $type['examples']),
        ];

        yield Finding::info(
            'body.type',
            "`{$token}` is recorded on {$where} ({$versions}) — e.g. ".implode(', ', $type['examples'])
            .'. Core neither reads nor upgrades it: it is stored as the app wrote it, returned in `data` '
            .'untouched, and upgraded at read time by whichever renderer knows the body type.'.$sampled,
            $subject,
        );

        if ($type['unversioned'] === 0) {
            return;
        }

        $newest = $type['versions'] === [] ? 1 : max($type['versions']);
        $missing = $type['unversioned'];

        if ($newest < 2) {
            yield Finding::info(
                'body.unversioned',
                "{$missing} of those ".str('row')->plural($rows).' '.($missing === 1 ? 'carries' : 'carry')
                .' no `'.FeedBody::VERSION.'`. They are version 1 — that is a DEFINITION, not a fallback — so '
                .'they read correctly today and will keep reading correctly. Have the class that writes them emit '
                ."`'".FeedBody::VERSION."' => 1` anyway: the key can only be added going forward, and the day a "
                .'version 2 ships, every row already in this table that never said which version it was is a row '
                .'nobody can classify.',
                $subject,
            );

            return;
        }

        yield Finding::warning(
            'body.version_ambiguous',
            "`{$token}` declares version {$newest} on some rows and no `".FeedBody::VERSION.'` at all on '
            .$missing.' '.($missing === 1 ? 'other' : 'others').' (e.g. '.implode(', ', $type['examples'])
            .'). The unversioned rows read as version 1, so they take the 1→'.$newest.' upgrade — which is the '
            .'WRONG path if they were in fact written by version '.$newest.' of the body type before it started declaring '
            .'itself. Nothing throws when that happens: the body upgrades down a path meant for an older shape '
            .'and renders output that looks entirely plausible. Establish what those rows are and set their '
            .'`'.FeedBody::VERSION.'`, or confirm they really are version 1.'.$sampled,
            $subject,
        );
    }

    /**
     * The newest rows of each table that carries a `data` column, keyed by id.
     *
     * Both tables, because a body hangs off either: an ACTIVITY's `data`
     * describes the act, and an entity SNAPSHOT's describes the noun — the
     * adapter's `Registry::forEntity()` reads the second and would find
     * nothing here if this check only read the first.
     *
     * @return array<string, Collection<int|string, array<array-key, mixed>>>
     */
    protected function sources(): array
    {
        $sources = [];

        if ($this->hasTable('activities')) {
            $sources['activity'] = $this->activities()
                ->orderByDesc('published_at')
                ->limit(self::SAMPLE)
                ->get(['id', 'data'])
                ->mapWithKeys(fn ($row) => [$row->getKey() => is_array($row->data) ? $row->data : []]);
        }

        if ($this->hasTable('snapshots')) {
            $model = config('storyfeed.models.snapshot', Snapshot::class);

            $sources['snapshot'] = $model::query()
                ->latest('updated_at')
                ->limit(self::SAMPLE)
                ->get(['id', 'data'])
                ->mapWithKeys(fn ($row) => [$row->getKey() => is_array($row->data) ? $row->data : []]);
        }

        // Both tables absent is `tables`' finding to report, not this one's:
        // a second voice saying the same thing is how a report gets long.
        return $sources;
    }

    /**
     * Every map in a `data` column that claims to be a body, whether or not
     * it succeeds at it.
     *
     * A map CLAIMS to be one by carrying either reserved key: the name without
     * a version, or a version without a name, are exactly the two failures
     * worth reporting, so both have to be caught by the same walk. A map with
     * neither key is the app's own data and is stepped over in silence.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    protected function walk(array $data, int $depth): array
    {
        if (array_key_exists(FeedBody::KEY, $data) || array_key_exists(FeedBody::VERSION, $data)) {
            // A body is a leaf by rule 4, so the walk stops rather than
            // looking inside one — the same stop the adapter's Registry makes,
            // for the same reason.
            return [$data];
        }

        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $found = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && ! in_array($key, self::RESERVED, true)) {
                $found = [...$found, ...$this->walk($value, $depth + 1)];
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $examples
     */
    protected function remember(array &$examples, string $where): void
    {
        if (count($examples) < self::EXAMPLES && ! in_array($where, $examples, true)) {
            $examples[] = $where;
        }
    }
}
