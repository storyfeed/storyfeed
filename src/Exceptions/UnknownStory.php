<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown when something names a Story that cannot be used.
 *
 * Loud on purpose. The alternative — publishing an activity with no verb, or
 * with a verb nothing authored a headline for — is a row in the feed that
 * renders a blank line, which is the failure class this whole layer exists to
 * make impossible.
 */
class UnknownStory extends LogicException
{
    public static function unregistered(string $story): self
    {
        return new self(
            "Story [{$story}] is not registered, so its verb and headlines were never compiled. "
            ."Bind it to its verb in routes/feed.php:\n\n"
            ."    Story::for(Document::class)->verb('upload', {$story}::class);\n\n"
            .'Publishing it anyway would record an activity nobody authored a headline for.'
        );
    }

    public static function classGivenAsObject(string $story, string $given): self
    {
        $short = class_basename($story);

        return new self(
            "{$short}'s \$this->activity() takes the activity's object, and was given the Story class [{$given}]. "
            .'Pass the model: $this->activity($this->document).'
        );
    }

    /**
     * story() or Storyfeed::route() on a name bound to a message class:
     * publishing it by name would skip the class's toFeedActivity().
     */
    public static function boundToMessage(string $name, string $story): self
    {
        $short = class_basename($story);

        return new self(
            "The story [{$name}] is bound to the message class [{$story}], so story('{$name}') would skip its "
            ."toFeedActivity(). Construct the class and publish it:\n\n"
            ."    Storyfeed::publish(new {$short}(…));"
        );
    }
}
