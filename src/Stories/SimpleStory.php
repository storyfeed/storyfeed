<?php

namespace Storyfeed\Stories;

class SimpleStory
{
    public string $type;

    public $actor;

    public $object;

    public $target;

    public $result;

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function actor($actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function object($object): self
    {
        $this->object = $object;

        return $this;
    }

    public function target($target): self
    {
        $this->target = $target;

        return $this;
    }

    public function result($result): self
    {
        $this->result = $result;

        return $this;
    }
}
