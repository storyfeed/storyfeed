<?php

namespace Storyfeed\Diagnostics\Checks;

use ReflectionClass;
use ReflectionMethod;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\SurfaceScanner;

/**
 * Feedable models whose label is guessed: they use InteractsWithFeed and
 * write none of `describeFeed()`, `toFeed()` or `guessFeedLabel()`, or were
 * registered with `Storyfeed::feedable()` and no `toFeedUsing()`.
 *
 * A model needs no feed code, and the guess ("Carrot Soup" from its `name`,
 * or "Dish #42") is often right. This is a list to look over once, not a
 * fault, so it is INFO. It is also the label a tombstone keeps when the
 * model asks it to (`keepLabel()`).
 *
 * Read from the classes, not from rows: a `describeFeed()` that sets no
 * label still counts as written, and an app-wide guesser
 * (`Storyfeed::guessFeedLabelsUsing()`) is still a guess.
 */
class GuessedLabels extends Check
{
    public function __construct(
        protected SurfaceScanner $scanner,
        protected Feedables $feedables,
    ) {}

    public function name(): string
    {
        return 'labels';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $guessed = [];

        foreach ($this->scanner->scan()['feedable'] as $class) {
            if ($this->guessesLabel($class)) {
                $guessed[] = $class;
            }
        }

        foreach ($this->feedables->registered() as $class) {
            if ($this->feedables->registration($class)?->describesFeed() === false) {
                $guessed[] = $class;
            }
        }

        $guessed = array_values(array_unique($guessed));
        sort($guessed);

        if ($guessed === []) {
            return;
        }

        yield Finding::info(
            'labels.guessed',
            count($guessed).' Feedable '.str('model')->plural(count($guessed)).' '
            .(count($guessed) === 1 ? 'is' : 'are').' labelled by guesswork (a `name` or `title`, else the noun '
            .'or class name and the key): '.implode(', ', $guessed).'. Fine if the guess reads well in a feed; '
            .'otherwise give '.(count($guessed) === 1 ? 'it' : 'each').' a label in `describeFeed()` '
            .'(or `toFeedUsing()` for a registered class).',
            ['models' => implode(', ', $guessed)],
        );
    }

    /** @param  class-string  $class */
    protected function guessesLabel(string $class): bool
    {
        if (! in_array(InteractsWithFeed::class, class_uses_recursive($class), true)) {
            return false;
        }

        $trait = (new ReflectionClass(InteractsWithFeed::class))->getFileName();

        // A trait's method reports the trait's file; one the model (or a base
        // model) wrote reports its own.
        foreach (['toFeed', 'describeFeed', 'guessFeedLabel'] as $method) {
            if ((new ReflectionMethod($class, $method))->getFileName() !== $trait) {
                return false;
            }
        }

        return true;
    }
}
