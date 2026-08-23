<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Storyfeed\Demo\Cast;
use Storyfeed\Demo\DemoSeeder;
use Storyfeed\Demo\Screenplay;
use Storyfeed\Demo\Vocabulary;

/**
 * Seed a demo tenant: a week of plausible activity, entirely invented.
 *
 * The position this command exists to make practical (docs/demo-data.md): a feed
 * is PII from its first row, snapshots are written at publish time, and there is
 * therefore no honest way to redact a production feed for a screenshot — you
 * would be rewriting history or filtering at presentation, and the second is the
 * one thing the read path must never do. So demo from seeded data instead, using
 * the same code production runs. This package will never ship a redactor.
 *
 * `--fresh` is safe by construction: it removes only activities whose verb
 * carries the `demo.` prefix this kit publishes under, so it cannot reach a row
 * the application wrote.
 */
class DemoCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'storyfeed:demo
        {--days=7 : How many days of history to seed}
        {--seed=1 : Deterministic seed — the same value reproduces the same feed}
        {--fresh : Remove previously seeded demo data first}
        {--clear : Remove seeded demo data and stop, seeding nothing}
        {--force : Run even in production}';

    protected $description = 'Seed a fictional demo tenant, so a demo never needs production data';

    public function handle(): int
    {
        // Production is where the hazard lives, in both directions: seeding fake
        // activities into a real feed, and running a demo against real people's
        // rows. Laravel's own confirmation is used rather than a bespoke check so
        // the behaviour is the one every Laravel developer already expects from
        // migrate:fresh — prompt interactively, refuse non-interactively, honour
        // --force.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear();
        }

        if ($this->option('fresh')) {
            $this->clear();
        }

        $days = max(1, (int) $this->option('days'));
        $seed = (int) $this->option('seed');

        $cast = Cast::studio();
        $screenplay = new Screenplay($cast, days: $days, seed: $seed);

        $published = (new DemoSeeder($cast, $screenplay))->seed();

        $this->info("Seeded {$published} demo activities across {$days} days (seed {$seed}).");
        $this->newLine();

        $this->line('  The cast is invented and lives only in the feed:');

        foreach ([
            'People' => $cast->members,
            'Clients' => $cast->clients,
            'Projects' => $cast->projects,
        ] as $role => $names) {
            $this->line(sprintf('    %-9s %s', $role, implode(', ', $names)));
        }

        $this->newLine();
        $this->line('  Three surfaces now have material:');
        $this->line('    world     Storyfeed::feed()->summary()');
        $this->line('    project   Storyfeed::feed()->context(Party::find(\''.Cast::keyFor($cast->projects[0]).'\'))');
        $this->line('    person    Storyfeed::feed()->actor(Party::find(\''.Cast::keyFor($cast->members[0]).'\'))->log()');
        $this->newLine();
        $this->line('  Every verb is prefixed <info>'.Vocabulary::PREFIX.'</info> — that is what makes '
            .'<info>--clear</info> unable to touch real rows.');

        // Seeding and RENDERING are separate opt-ins, and the second one is easy
        // to miss in the worst way: this command registered the grammar in its
        // own process, so the feed it just seeded reads perfectly from here and
        // renders with null headlines in the app. Say so, here, where the
        // operator is looking.
        if (! config('storyfeed.demo.enabled', false)) {
            $this->newLine();
            $this->components->warn(
                'storyfeed.demo.enabled is off, so the demo grammar is not registered at boot — '
                .'the seeded feed will render with empty headlines. Turn it on in the environment '
                .'doing the demo (config/storyfeed.php).',
            );
        }

        return self::SUCCESS;
    }

    private function clear(): int
    {
        $removed = DemoSeeder::fresh();

        $this->info("Removed {$removed['activities']} demo activities and {$removed['parties']} demo parties.");

        return self::SUCCESS;
    }
}
