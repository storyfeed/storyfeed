<?php

namespace Storyfeed\Grouping;

/**
 * The closure form of `grouped()`: one method per built-in axis, each taking
 * the group headline and appending a {@see Group}.
 *
 *     Story::verb('complete')->grouped(fn (GroupBuilder $group) => $group
 *         ->repeat(':actor completed :count tasks')
 *         ->actors(':actors completed tasks')
 *         ->axis('project', ':actors completed project tasks'));
 *
 * It produces exactly the Group objects `Group::repeat()->headline(…)` would,
 * so Story classes and the registrar share one concept. A custom axis goes
 * through axis(), because a builder can only declare the axes it knows about.
 */
final class GroupBuilder
{
    /** @var list<Group> */
    private array $groups = [];

    /** One actor repeating the same act — "Sally completed 5 tasks". */
    public function repeat(?string $headline = null): self
    {
        return $this->add(Group::repeat(), $headline);
    }

    /** Many actors, one target — "Bob, Sally and 3 others uploaded files to X". */
    public function actors(?string $headline = null): self
    {
        return $this->add(Group::byActors(), $headline);
    }

    /** One actor, many targets — "Sally commented in 3 projects". */
    public function targets(?string $headline = null): self
    {
        return $this->add(Group::byTargets(), $headline);
    }

    /** One actor, one object, repeatedly — "Sally made 5 revisions to X". */
    public function object(?string $headline = null): self
    {
        return $this->add(Group::byObject(), $headline);
    }

    /** The digest's phrase, starting at the verb — "completed :count tasks". */
    public function summary(?string $headline = null): self
    {
        return $this->add(Group::summary(), $headline);
    }

    /**
     * An authored collection story (see Contracts\Bundleable), with the
     * singular headline its object-less parent needs.
     */
    public function composite(?string $headline = null, ?string $parentHeadline = null): self
    {
        $group = Group::composite();

        if ($parentHeadline !== null) {
            $group->parentHeadline($parentHeadline);
        }

        return $this->add($group, $headline);
    }

    /** Every axis — the `*.{verb}` aggregate key. */
    public function any(?string $headline = null): self
    {
        return $this->add(Group::any(), $headline);
    }

    /** A registered axis by name, built-in or custom. */
    public function axis(string $axis, ?string $headline = null): self
    {
        return $this->add(Group::on($axis), $headline);
    }

    /**
     * @return list<Group>
     *
     * @internal
     */
    public function toGroups(): array
    {
        return $this->groups;
    }

    private function add(Group $group, ?string $headline): self
    {
        if ($headline !== null) {
            $group->headline($headline);
        }

        $this->groups[] = $group;

        return $this;
    }
}
