<?php

namespace Storyfeed\Tests\Fixtures;

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/** Disposable studio data adapted from P2's NewsroomHistory: nine people, six verbs, body-bearing documents and project contexts. */
final class NewsroomBurstDay
{
    public static function seed(): void
    {
        config()->set('storyfeed.grouping.batch.enabled', false);
        foreach (['comment' => 'commented on', 'add' => 'added', 'upload' => 'uploaded', 'revise' => 'revised', 'approve' => 'approved', 'assign' => 'assigned'] as $verb => $past) {
            Story::verb($verb)->headline(":actor {$past} :object[ in :context]")->grouped(
                Group::repeat()->headline(":actor {$past} :count documents[ in :target]"),
                Group::byActors()->headline(":actors {$past} :objects[ in :target]"),
                Group::byTargets()->headline(":actor {$past} :count documents[ across :targets]"),
                Group::byObject()->headline(":actor {$past} :object :count times"),
            );
        }
        $people = collect(['Priya', 'Bob', 'Sally', 'Tomás', 'Mei', 'Dana', 'Alex', 'Sam', 'Jules'])
            ->map(fn ($name, $i) => User::create(['name' => $name, 'email' => "person{$i}@example.com"]));
        $places = collect(['Spring Campaign', 'Autumn Launch', 'Website Refresh', 'Brand Kit', 'Social Campaign', 'Annual Report', 'Event Launch', 'Newsletter', 'Client Portal'])
            ->map(fn ($name) => Customer::create(['name' => $name]));
        $documents = collect(range(1, 120))->map(fn ($i) => Delivery::create(['tracking_number' => $i === 1 ? 'tokens.pdf' : "Document {$i}.pdf"]));
        $day = now()->subDay()->startOfDay();
        // Three sittings separated by hours; several actions within each sitting.
        foreach ([9, 12, 15] as $sitting => $hour) {
            foreach ($people as $person => $actor) {
                foreach (['upload', 'comment', 'approve', 'add', 'revise', 'assign'] as $v => $verb) {
                    foreach (range(0, 3) as $j) {
                        Storyfeed::activity()->actor($actor)->verb($verb, $documents[($person * 11 + $v * 4 + $j) % 120])
                            ->target($places[$person])->context($places[$person])
                            ->publishedAt($day->copy()->addHours($hour)->addMinutes($person * 2 + $v)->addSeconds($j * 20))->publish();
                    }
                }
            }
            // A social thread on one document competes with people's own runs.
            foreach ($people->take(4) as $i => $actor) {
                Storyfeed::activity()->actor($actor)->verb('comment', $documents[0])->target($places[0])->context($places[0])
                    ->publishedAt($day->copy()->addHours($hour)->addMinutes(40 + $i))->publish();
            }
        }
    }
}
