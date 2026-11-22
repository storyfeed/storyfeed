<?php

namespace Storyfeed\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate a domain event that publishes to the feed through PublishesToFeed:
 *
 *   php artisan make:feed-event OrderPlaced --verb=place
 *
 * Storyfeed is a side effect, never the source: the app dispatches its own
 * event, business listeners subscribe to it, and core's single listener
 * publishes what toFeedActivity() returns.
 */
class FeedEventMakeCommand extends GeneratorCommand
{
    protected $name = 'make:feed-event';

    protected $description = 'Create a domain event that publishes to the feed';

    protected $type = 'Event';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/storyfeed.feed-event.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/feed-event.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Events';
    }

    protected function buildClass($name): string
    {
        $verb = (string) ($this->option('verb') ?: Str::snake(Str::beforeLast(class_basename($name), 'ed') ?: class_basename($name)));

        return str_replace('{{ verb }}', $verb, parent::buildClass($name));
    }

    /** @return array<int, array<int, mixed>> */
    protected function getOptions(): array
    {
        return [
            ['verb', null, InputOption::VALUE_OPTIONAL, 'The verb the event publishes'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing event'],
        ];
    }
}
