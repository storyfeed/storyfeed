<?php

namespace Storyfeed\Healing;

/** One in-memory result. Runs are not persisted. */
final readonly class HealResult
{
    public function __construct(
        public string $healer,
        public ActivityRetirement $candidate,
        public HealOutcome $outcome,
    ) {}
}
