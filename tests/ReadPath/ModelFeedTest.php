<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * `$model->storyfeed()` is a shortcut for `Storyfeed::feed()->involving($model)`
 * and nothing else. These tests assert that equivalence rather than the
 * behaviour underneath it — the moment the two diverge, one of them is a lie,
 * and the shortcut is the one people will reach for.
 */
it('returns the same page as the facade form', function () {
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $file = Delivery::create(['tracking_number' => 'annual-report.fig']);
    $project = Customer::create(['name' => 'Password Crackdown']);

    Storyfeed::activity()->actor($user)->verb('upload', $file)->to($project)->publish();
    Storyfeed::activity()->actor($user)->verb('revise', $file)->to($project)->publish();

    $shortcut = $project->storyfeed()->get();
    $facade = Storyfeed::feed()->involving($project)->get();

    expect($shortcut->items())->toEqual($facade->items())
        ->and($shortcut->toArray()['next_cursor'])->toBe($facade->toArray()['next_cursor']);
});

it('finds activities by every role the model fills', function () {
    $user = User::create(['name' => 'Aiko', 'email' => 'aiko@example.com']);
    $file = Delivery::create(['tracking_number' => 'wireframes.sketch']);
    $client = Customer::create(['name' => 'Acme']);
    $workspace = Customer::create(['name' => 'Workspace']);

    Storyfeed::activity()
        ->actor($user)
        ->verb('upload', $file)
        ->to($client)
        ->context($workspace)
        ->publish();

    foreach ([$user, $file, $client, $workspace] as $model) {
        expect($model->storyfeed()->get()->items())
            ->toHaveCount(1, $model::class.' should find the activity it took part in');
    }
});

it('excludes activities the model had no part in', function () {
    $user = User::create(['name' => 'Marcus', 'email' => 'marcus@example.com']);
    $mine = Customer::create(['name' => 'Mine']);
    $theirs = Customer::create(['name' => 'Theirs']);

    Storyfeed::activity()->actor($user)->verb('upload', $mine)->publish();

    expect($theirs->storyfeed()->get()->items())->toHaveCount(0);
});

it('composes with the rest of the builder', function () {
    $user = User::create(['name' => 'Priya', 'email' => 'priya@example.com']);
    $project = Customer::create(['name' => 'Port Migration']);
    $file = Delivery::create(['tracking_number' => 'style-tile.sketch']);

    Storyfeed::activity()->actor($user)->verb('upload', $file)->to($project)->publish();
    Storyfeed::activity()->actor($user)->verb('comment', $file)->to($project)->publish();

    expect($project->storyfeed()->verb('upload')->get()->items())->toHaveCount(1)
        ->and($project->storyfeed()->log()->limit(1)->get()->items())->toHaveCount(1)
        ->and($project->storyfeed()->get()->items())->toHaveCount(2);
});

it('intersects when involving() is called again', function () {
    $ines = User::create(['name' => 'Ines', 'email' => 'ines2@example.com']);
    $marcus = User::create(['name' => 'Marcus', 'email' => 'marcus2@example.com']);
    $project = Customer::create(['name' => 'Metaverse Pivot']);
    $file = Delivery::create(['tracking_number' => 'hero.fig']);

    Storyfeed::activity()->actor($ines)->verb('upload', $file)->to($project)->publish();
    Storyfeed::activity()->actor($marcus)->verb('upload', $file)->to($project)->publish();

    expect($project->storyfeed()->get()->items())->toHaveCount(2)
        ->and($project->storyfeed()->involving($ines)->get()->items())->toHaveCount(1);
});

it('pages with a cursor like any other feed', function () {
    $user = User::create(['name' => 'Deja', 'email' => 'deja@example.com']);
    $project = Customer::create(['name' => 'Verification Tiers']);

    foreach (range(1, 3) as $n) {
        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "file-{$n}.fig"]))
            ->to($project)
            ->publish();
    }

    $first = $project->storyfeed()->log()->limit(2)->get();
    $second = $project->storyfeed()->log()->limit(2)->cursor($first->toArray()['next_cursor'])->get();

    $ids = collect($first->items())->concat($second->items())->pluck('id');

    expect($ids)->toHaveCount(3)
        ->and($ids->unique())->toHaveCount(3);
});
