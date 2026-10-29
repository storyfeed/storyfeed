<?php

namespace Workbench\App\Feed\Changelog;

use Storyfeed\Contracts\StaticItem;
use Storyfeed\Sources\Entry;

class ReleaseOne implements StaticItem
{
    public function entry(): Entry
    {
        return Entry::make('ship', '2026-09-01 09:00', actor: 'Storyfeed', object: ['type' => 'release', 'label' => 'v1.0.0']);
    }
}
