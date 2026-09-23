<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\Feedables;
use Storyfeed\Tests\Fixtures\Models\FeedablePhoto;
use Storyfeed\Tests\Fixtures\Models\Photo;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->morphMap = Relation::morphMap();
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

afterEach(function () {
    Relation::morphMap($this->morphMap, false);
});

it('names a Feedable subclass deleted through its parent, and says the listener is active', function () {
    Relation::morphMap(['feedable_photo' => FeedablePhoto::class]);
    app(Feedables::class)->listenThroughParents(Relation::morphMap());
    Storyfeed::activity()->actor($this->ines)->verb('upload', FeedablePhoto::create(['file_name' => 'a.jpg']))->publish();

    $report = Storyfeed::doctor(['inherited']);
    $finding = $report->withCode('inherited.parent_deletes')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject)->toBe([
            'alias' => 'feedable_photo',
            'class' => FeedablePhoto::class,
            'parents' => Photo::class,
            'listening' => true,
        ])
        ->and($finding->message)->toContain('is deleted through '.Photo::class)
        ->and($finding->message)->toContain('arrive at deletion time')
        ->and($finding->message)->toContain('Updates are not heard through '.Photo::class.' either way: a '.FeedablePhoto::class.' updated as its parent keeps its snapshot until the trickle runs.')
        ->and($report->isHealthy())->toBeTrue();
});

it('says tombstones wait for the trickle when nothing listens', function () {
    Relation::morphMap(['feedable_photo' => FeedablePhoto::class]);
    $activity = Storyfeed::activity()->actor($this->ines)->verb('upload', FeedablePhoto::create(['file_name' => 'a.jpg']))->publish();

    // Stored by class name, as an app without a morph map entry would.
    DB::table('feed_activities')->where('id', $activity->id)->update(['object_type' => FeedablePhoto::class]);

    $finding = Storyfeed::doctor(['inherited'])->withCode('inherited.parent_deletes')->sole();

    expect($finding->subject['listening'])->toBeFalse()
        ->and($finding->message)->toContain("on the trickle's schedule")
        ->and($finding->message)->toContain('keeps its snapshot until the trickle runs');
});

it('says nothing for a Feedable model with no such parent', function () {
    Storyfeed::activity()->actor($this->ines)->verb('greet', $this->ines)->publish();

    expect(Storyfeed::doctor(['inherited'])->all())->toBeEmpty();
});
