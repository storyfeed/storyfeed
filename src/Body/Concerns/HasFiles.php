<?php

namespace Storyfeed\Body\Concerns;

use Storyfeed\FeedResource;

/**
 * The files a body names, as {@see FeedResource} values. Each `files()` call
 * appends; `withFiles()` replaces.
 */
trait HasFiles
{
    /** @var list<FeedResource> */
    protected array $files = [];

    /**
     * Add the files this body names. Each call APPENDS, in the order given.
     *
     * @param  FeedResource|iterable<FeedResource>  ...$files
     */
    public function files(FeedResource|iterable ...$files): static
    {
        foreach ($files as $attachment) {
            foreach ($attachment instanceof FeedResource ? [$attachment] : $attachment as $file) {
                $this->files[] = $file;
            }
        }

        return $this;
    }

    /**
     * Name the files this body draws, replacing any already named.
     *
     * At least one is REQUIRED, so that the day the bool was retired lands
     * as an error in the fluent form too. `withFiles()` meaning "draw
     * the entity's files" is the shape that went away; accepting the same
     * call and quietly producing an empty list would make an upgrade look
     * like it worked.
     */
    public function withFiles(FeedResource $file, FeedResource ...$more): static
    {
        $this->files = [$file, ...$more];

        return $this;
    }

    /** @return list<FeedResource> */
    public function getFiles(): array
    {
        return $this->files;
    }
}
