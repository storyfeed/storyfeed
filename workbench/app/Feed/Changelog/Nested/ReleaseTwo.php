<?php

namespace Workbench\App\Feed\Changelog\Nested;

use Storyfeed\Contracts\StaticItem;

class ReleaseTwo implements StaticItem
{
    public function entry(): array
    {
        return [
            'verb' => 'ship',
            'actor' => 'Storyfeed',
            'object' => ['type' => 'release', 'label' => 'v2.0.0'],
            'published_at' => '2026-09-20 09:00',
        ];
    }
}
