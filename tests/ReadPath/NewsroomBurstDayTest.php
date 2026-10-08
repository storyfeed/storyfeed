<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Tests\Fixtures\NewsroomBurstDay;

it('shows the first thirty Live headlines for a realistic studio day', function () {
    NewsroomBurstDay::seed();
    $items = Storyfeed::feed()->live()->limit(30)->get()->collect();
    expect($items)->toHaveCount(30);
    if ($path = env('STORYFEED_BURST_LISTING')) {
        $lines = $items->values()->map(fn ($item, $i) => ($i + 1).'. '.$item->headline()->toString().' — count '.$item->count().' ('.($item->axis() ?? 'solo').')');
        file_put_contents($path, $lines->implode("\n")."\n");
    }
});
