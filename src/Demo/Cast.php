<?php

namespace Storyfeed\Demo;

use Illuminate\Support\Str;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Models\Party;

/**
 * The fictional world a demo feed is populated from.
 *
 * Every member of the cast is a Party — a named participant that lives only in
 * the feed — rather than a model in the host application. That is the decision
 * this class exists to encode, and it is what makes the kit shippable as a
 * package feature: a demo built on Parties needs no migrations, no factories
 * and no domain models, so it seeds identically in the Newsroom, in a case-study
 * app, and in a fresh `laravel new`. It also exercises the real write path —
 * party resolution, snapshots, grouping, curation — rather than a demo-only
 * shortcut, which is the whole point of demoing from seeded data instead of
 * redacting production.
 *
 * What the trade costs: every entity shares the `storyfeed.party` morph alias,
 * so a demo cannot show type-keyed grammar (`invoice.*`) or a link resolver
 * pointing at real records. Both are documented in docs/demo-data.md, and both
 * are recoverable by an app that passes its own models in — the seeder takes
 * whatever the cast hands it and never assumes a Party.
 *
 * Keys are prefixed `demo-` so a party this kit created is identifiable at a
 * glance and removable without guessing.
 */
class Cast
{
    /** @var array<string, Party> */
    private array $resolved = [];

    /**
     * @param  list<string>  $members  the people who act
     * @param  list<string>  $clients  the accounts work is done for
     * @param  list<string>  $projects  the containers feeds get scoped to
     * @param  list<string>  $documents  the things uploaded, revised, approved
     * @param  list<string>  $tasks  the things completed
     */
    public function __construct(
        public readonly array $members,
        public readonly array $clients,
        public readonly array $projects,
        public readonly array $documents,
        public readonly array $tasks,
    ) {}

    /**
     * The shipped cast: a small studio, deliberately the archetypal operational
     * portal rather than a quirky demo domain — a visitor should see their own
     * app in it. Names are invented and the studio does not exist; that is the
     * feature, not a disclaimer.
     */
    public static function studio(): self
    {
        return new self(
            members: [
                'Priya Raman',
                'Bo Feldman',
                'Sanne de Vries',
                'Marcus Adeyemi',
                'Iris Lindqvist',
            ],
            clients: [
                'Cobble & Co.',
                'Halcyon Foods',
                'Northwind Press',
            ],
            projects: [
                'Brand Refresh',
                'Q3 Campaign',
                'Site Rebuild',
            ],
            // Deliberately more documents than the largest upload burst: the
            // burst draws DISTINCT files, so a pool smaller than the burst would
            // silently cap it and shrink the very group it exists to produce.
            documents: [
                'Moodboard v2',
                'Style Guide',
                'Launch Brief',
                'Photo Set A',
                'Wireframes',
                'Colour Study',
                'Type Specimen',
                'Packaging Comps',
                'Site Map',
                'Motion Boards',
            ],
            tasks: [
                'Draft launch copy',
                'Review artwork',
                'Ship staging build',
                'Compress hero images',
            ],
        );
    }

    /**
     * Resolve a name to its Party row, creating it on first use and reusing it
     * after — the same firstOrNew path an application gets from `Party::make()`,
     * so the demo is not taking a private door.
     */
    public function party(string $name): Party
    {
        // Through the configured party model rather than the concrete class,
        // exactly as StoryfeedManager::party() does: an app that swapped
        // `storyfeed.models.party` gets its own model seeded, and the kit has no
        // private door the application does not also have.
        $model = config('storyfeed.models.party', Party::class);

        return $this->resolved[$name] ??= $model::make(
            $name,
            key: self::keyFor($name),
            type: $this->typeFor($name),
        );
    }

    /** The party key this kit uses for a given display name. */
    public static function keyFor(string $name): string
    {
        return 'demo-'.Str::slug($name);
    }

    /** Every name in the cast, in role order. @return list<string> */
    public function names(): array
    {
        return [...$this->members, ...$this->clients, ...$this->projects, ...$this->documents, ...$this->tasks];
    }

    /**
     * The AS2.0 type for a name, by the role it holds in this cast. Typing them
     * properly is what lets the AS2.0 surface be part of the demo rather than a
     * page of `Service` nodes.
     */
    private function typeFor(string $name): ObjectType
    {
        return match (true) {
            in_array($name, $this->members, true) => ObjectType::Person,
            in_array($name, $this->clients, true) => ObjectType::Organization,
            in_array($name, $this->projects, true) => ObjectType::Object,
            in_array($name, $this->documents, true) => ObjectType::Document,
            default => ObjectType::Note,
        };
    }
}
