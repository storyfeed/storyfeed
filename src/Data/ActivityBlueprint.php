<?php

namespace Storyfeed\Data;

use Spatie\LaravelData\Data;

class ActivityBlueprint extends Data
{
    public function __construct(
        public string $type,
        public string $verb,
        public string $subject,
        public string $subjectId,
        public string $object,
        public string $objectId,
        public string $target,
        public string $targetId,
        public string $context,
        public string $contextId,
    ) {
    }
}
