<?php

use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story as StoryFacade;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasDispatched;
use Workbench\App\Models\Delivery;

/*
 * The four conditions the raw registries accept SILENTLY, now boot failures.
 * Each assertion checks the MESSAGE, not just that something threw — a message
 * that names the fix is the difference between a puzzle and an instruction, and
 * it is the reason doctor's output taught faster than any changelog.
 */

it('refuses a story with no object type, and says why it is not inferred', function () {
    $story = new class extends Story
    {
        public string|FeedVerb|BackedEnum|null $verb = 'confirm';

        public function toFeedActivity(): ?PendingActivity
        {
            return $this->activity();
        }

        public function headline(): string
        {
            return ':actor confirmed :object';
        }
    };

    expect(fn () => Verb::fromStory($story::class, null, 'confirm', 'a line'))
        ->toThrow(StoryMisconfigured::class, 'must declare $objectType');

    try {
        Verb::fromStory($story::class, null, 'confirm', 'a line');
    } catch (StoryMisconfigured $e) {
        // The reason matters: token-guessing on class names is what died.
        expect($e->getMessage())->toContain('CreatePurchaseOrder');
    }
});

it('compiles a message whose constructor needs its data, without calling it', function () {
    StoryFacade::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);

    // DeliveryWasDispatched's constructor requires a Delivery: `new` at
    // compile would be an ArgumentCountError.
    expect(Storyfeed::template('delivery', 'dispatch'))->toBe(':actor dispatched :object');
});

it('refuses presentation that reads constructor state, naming the class', function () {
    StoryFacade::for(Delivery::class)->verb('dispatch', ReadsItsDelivery::class);

    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, '['.ReadsItsDelivery::class.'] read constructor state while it compiled')
        ->and(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, 'Say per-publish things in toFeedActivity() instead');
});

it('refuses an unpinned aggregate token before any traffic exists', function () {
    defineStories(
        Verb::make('delivery.revise')
            ->headline(':actor revised :object')
            // :object on the repeat axis is the documented lie: the group can
            // span five different documents.
            ->groups(Group::repeat()->headline(':actor made :count revisions to :object'))
    );

    try {
        Storyfeed::registeredAggregateGrammar();
        $this->fail('Expected a StoryMisconfigured.');
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toContain(':object')
            ->toContain('repeat')
            ->toContain('Aut Beatae.docx');
    }
});

it('refuses a group on an unregistered axis', function () {
    defineStories(
        Verb::make('delivery.confirm')
            ->headline(':actor confirmed :object')
            ->groups(Group::on('nonexistent')->headline(':actors confirmed :count'))
    );

    try {
        Storyfeed::registeredAggregateGrammar();
        $this->fail('Expected a StoryMisconfigured.');
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toContain('[nonexistent]')
            ->toContain('never resolve')
            // Names the registered axes, so the fix is obvious.
            ->toContain('repeat');
    }
});

it('refuses a composite without parent grammar, and refuses to suggest *.*', function () {
    defineStories(
        Verb::make('delivery.upload')
            ->headline(':actor uploaded :object')
            // No ->parentHeadline(): the parent would render a blank line.
            ->groups(Group::composite()->headline(':actor uploaded :objects'))
    );

    try {
        Storyfeed::registeredAggregateGrammar();
        $this->fail('Expected a StoryMisconfigured.');
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toContain("'*.upload'")
            ->toContain('parentHeadline')
            ->toContain('Do NOT reach for `*.*`');
    }
});

it('accepts parent grammar from another story instead of parentHeadline', function () {
    // The invariant is that the entry EXISTS, not where it came from.
    defineStories(
        Verb::make('*.upload')->headline(':actor uploaded deliveries'),
        Verb::make('delivery.upload')
            ->headline(':actor uploaded :object')
            ->groups(Group::composite()->headline(':actor uploaded :objects'))
    );

    expect(Storyfeed::aggregateTemplate('composite', 'upload'))->toBe(':actor uploaded :objects');
});

it('refuses two stories authoring the same key', function () {
    defineStories(
        Verb::make('delivery.confirm', 'FirstStory')->headline(':actor confirmed :object'),
        Verb::make('delivery.confirm', 'SecondStory')->headline(':actor OK-ed :object')
    );

    try {
        Storyfeed::registeredGrammar();
        $this->fail('Expected a StoryMisconfigured.');
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toContain('FirstStory')
            ->toContain('SecondStory')
            // The arrays are last-writer-wins; naming it is the new guarantee.
            ->toContain('last-writer-wins');
    }
});

it('refuses Group::min(), pointing at the axis instead of ignoring it', function () {
    // Silently ignoring it, or mutating the shared axis from whichever story
    // compiled last, are both the quiet-failure class this package removed
    // magic to escape.
    expect(fn () => Group::byActors()->min(3))
        ->toThrow(StoryMisconfigured::class, 'eligibility belongs to the AXIS');

    try {
        Group::byActors()->min(3);
    } catch (StoryMisconfigured $e) {
        expect($e->getMessage())->toContain("eligibleWhenDistinct('actor', min: 3)");
    }
});

it('refuses a typod option rather than dropping it', function () {
    expect(fn () => Verb::make('delivery.confirm')->fill(['headine' => ':actor confirmed'], 'delivery.confirm'))
        ->toThrow(StoryMisconfigured::class, 'unrecognized option [headine]');
});

it('refuses a malformed ad-hoc key', function () {
    expect(fn () => Verb::make('confirm'))
        ->toThrow(StoryMisconfigured::class, '`{type}.{verb}` form');
});

it('refuses a non-Story class at the line that binds it', function () {
    StoryFacade::for(Delivery::class)->verb('confirm', Delivery::class);
})->throws(StoryMisconfigured::class, 'is not a message class');

class ReadsItsDelivery extends Story
{
    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity($this->delivery);
    }

    public function headline(): string
    {
        return ':actor dispatched '.$this->delivery->tracking_number;
    }
}
