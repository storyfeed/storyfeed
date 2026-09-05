<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the core stays headless — no view or UI dependencies')
    ->expect('Storyfeed')
    ->not->toUse(['Illuminate\View', 'Illuminate\Support\Facades\View', 'Illuminate\Support\Facades\Blade']);

arch('contracts are interfaces')
    ->expect('Storyfeed\Contracts')
    ->toBeInterfaces();

arch('value objects are final')
    ->expect(['Storyfeed\FeedEntity', 'Storyfeed\FeedContext', 'Storyfeed\FeedMedia', 'Storyfeed\FeedImage', 'Storyfeed\FeedNoun'])
    ->toBeFinal();
