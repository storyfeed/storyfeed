<?php

namespace Storyfeed;

use ReflectionClass;

class Story
{
    public $type = null;

    public $actorId = null;

    public $actorType = null;

    public $objectId = null;

    public $objectType = null;

    public $targetId = null;

    public $targetType = null;

    private $tokens = [];

    public function __construct()
    {
        $reflection = new ReflectionClass(get_called_class());
        $class = $reflection->getShortName();
        $this->tokens = str($class)->snake()->explode('_');

        if (! $this->type) {
            $this->type = $this->guessType();
        }

        if (! $this->objectType) {
            $this->objectType = $this->guessObjectType();
        }
    }

    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function actor($type, $id): self
    {
        $this->actorType = $type;
        $this->actorId = $id;

        return $this;
    }

    public function actorId($id): self
    {
        $this->actorId = $id;

        return $this;
    }

    public function actorType($actor): self
    {
        $this->actorType = $actor;

        return $this;
    }

    public function object($type, $id): self
    {
        $this->objectType = $type;
        $this->objectId = $id;

        return $this;
    }

    public function objectId($id): self
    {
        $this->objectId = $id;

        return $this;
    }

    public function objectType($object): self
    {
        $this->objectType = $object;

        return $this;
    }

    public function target($type, $id): self
    {
        $this->targetType = $type;
        $this->targetId = $id;

        return $this;
    }

    public function targetId($id): self
    {
        $this->targetId = $id;

        return $this;
    }

    public function targetType($target): self
    {
        $this->targetType = $target;

        return $this;
    }

    protected function guessType()
    {
        return data_get($this->tokens, 0);
    }

    protected function guessObjectType()
    {
        return data_get($this->tokens, 1);
    }

    public static function makeFrom($params = [])
    {
        $story = new static();
        $story->actorType = data_get($params, 'actor');
        $story->objectType = data_get($params, 'object');
        $story->targetType = data_get($params, 'target');

        return $story;
    }

    public function headline()
    {
        return '';
    }
}
