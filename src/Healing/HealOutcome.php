<?php

namespace Storyfeed\Healing;

/** What happened, or would happen in a dry run. Neither outcome creates a story. */
enum HealOutcome: string
{
    case Retired = 'retire';
    case Unchanged = 'unchanged';
}
