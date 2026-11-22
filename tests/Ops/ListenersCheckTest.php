<?php

use Illuminate\Support\Facades\Event;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;

class GrantsAccessOnActivity
{
    public function handle(ActivityPublished $event): void {}
}

it('warns about app listeners on Storyfeed events and ignores its own', function () {
    expect(Storyfeed::doctor(['listeners'])->withCode('listeners.business'))->toHaveCount(0);

    Event::listen(ActivityPublished::class, GrantsAccessOnActivity::class);
    Event::listen(ActivityDeleted::class, fn () => null);
    Event::listen(BatchClosed::class, fn () => null);

    $findings = Storyfeed::doctor(['listeners'])->withCode('listeners.business');

    expect($findings)->toHaveCount(3)
        ->and($findings->first()->message)->toContain('GrantsAccessOnActivity')->toContain('side effect');
});

it('generates a PublishesToFeed event', function () {
    $path = app_path('Events/OrderPlaced.php');
    @unlink($path);

    $this->artisan('make:feed-event', ['name' => 'OrderPlaced', '--verb' => 'place'])->assertSuccessful();

    expect(file_get_contents($path))->toContain('implements PublishesToFeed')->toContain("action('place'");
    @unlink($path);
});
