<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\DottedVerb;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\Delivery;

enum DottedBackedVerb: string
{
    case Email = 'document.email';
}

enum DottedFeedVerb: string implements FeedVerb
{
    case Email = 'document.email';

    public function verb(): string
    {
        return $this->value;
    }

    public function activityType(): ActivityType
    {
        return ActivityType::Update;
    }
}

it('rejects dotted declarations and publishing regardless of strict mode', function ($verb) {
    config(['storyfeed.verbs.strict' => false]);

    foreach ([
        fn () => Story::for(Delivery::class)->verb($verb),
        fn () => Storyfeed::activity()->verb($verb)->publish(),
    ] as $declare) {
        expect($declare)->toThrow(DottedVerb::class, 'Verb [document.email] may not contain a dot; record the type as the object; use ->name() for dotted lookups.');
    }

    expect(Activity::count())->toBe(0);
})->with(['document.email', DottedBackedVerb::Email, DottedFeedVerb::Email]);

it('rejects dotted registry and resource definition verbs before caching', function () {
    expect(fn () => Story::resource(Delivery::class)->only('document.email'))->toThrow(DottedVerb::class)
        ->and(fn () => Verb::make('document.document.email'))->toThrow(DottedVerb::class)
        ->and(fn () => Storyfeed::verbs(['document.email' => ActivityType::Update]))->toThrow(DottedVerb::class)
        ->and(fn () => Storyfeed::verbs(DottedFeedVerb::class))->toThrow(DottedVerb::class);

    expect(Storyfeed::declaredVerb('document.email'))->toBeFalse();
});

it('preserves camelCase and other free-form verbs and dotted names', function (string $verb) {
    Story::for(Delivery::class)->verb($verb)->name('document.'.$verb);
    $document = Delivery::create(['tracking_number' => 'DOC']);

    expect(Storyfeed::activity($verb, $document)->publish()->verb)->toBe($verb);
})->with(['updateStatus', 'email', 'custom_action', 'a%b']);

it('reads historical dotted rows and reports their counts without migrating them', function () {
    $document = Delivery::create(['tracking_number' => 'DOC']);
    $activities = collect([1, 2])->map(fn () => Activity::create([
        'verb' => 'document.email',
        'object_type' => $document->getMorphClass(),
        'object_id' => $document->getKey(),
        'published_at' => now(),
    ]));

    expect(collect(Storyfeed::feed()->object($document)->log()->get()->toArray())->pluck('verb')->all())
        ->toBe(['document.email', 'document.email']);

    $this->artisan('storyfeed:doctor', ['--only' => 'verbs'])
        ->expectsOutputToContain('Stored verb `document.email` contains a dot (2 activities)')
        ->assertSuccessful();

    $finding = collect(Storyfeed::doctor(['verbs'])->all())->firstWhere('code', 'verbs.dotted');
    expect($finding->subject)->toBe(['verb' => 'document.email', 'count' => 2])
        ->and($finding->message)->toContain('Migrate it to the action alone');

    expect(Activity::where('verb', 'document.email')->count())->toBe(2)
        ->and(serialize_one($activities->first())['sf:verb'])->toBe('document.email');
});
