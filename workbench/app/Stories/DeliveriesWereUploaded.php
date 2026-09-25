<?php

namespace Workbench\App\Stories;

use BackedEnum;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * A composite story — one act over a collection of objects.
 *
 * `parentHeadline()` is the part the raw registries hide: a composite's parent
 * activity has no object of its own, so `delivery.upload` never resolves for
 * it and it needs `*.upload` instead. Declaring both on one line is what lets
 * the compiler refuse a composite that would ship a null-headline parent —
 * a trap a real consumer found only from doctor output after following every
 * documented step.
 */
class DeliveriesWereUploaded extends Story
{
    public string|array|null $objectType = Delivery::class;

    public string|FeedVerb|BackedEnum|null $verb = ActivityVerb::Upload;

    /** @param  iterable<int, Delivery>  $deliveries */
    public function __construct(
        public iterable $deliveries,
        public User $user,
    ) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity()->objects($this->deliveries)->by($this->user);
    }

    public function headline(): string
    {
        return ':actor uploaded :object';
    }

    public function icon(): ?string
    {
        return 'bi-upload';
    }

    public function groups(): array
    {
        return [
            Group::composite()
                ->headline(':actor uploaded :objects')
                ->parentHeadline(':actor uploaded deliveries'),
            Group::repeat()->headline(':actor uploaded :count deliveries'),
        ];
    }
}
