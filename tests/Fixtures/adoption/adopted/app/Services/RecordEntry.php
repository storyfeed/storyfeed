<?php

namespace App\Services;

use App\Models\Entry;
use Storyfeed\Facades\Storyfeed;

class RecordEntry
{
    public function record(Entry $entry): void
    {
        Storyfeed::record('create', object: $entry);
    }
}
