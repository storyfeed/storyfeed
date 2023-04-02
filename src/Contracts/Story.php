<?php

namespace Storyfeed\Contracts;

interface Story
{
    // The activity type this story corresponds to.
    public function activityType(): string;

    public function actor(): string;

    public function object(): string;

    public function target(): string;

    public function verb(): string;

    public function metadata(): array;
}
