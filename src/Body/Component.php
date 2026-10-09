<?php

namespace Storyfeed\Body;

use Storyfeed\FeedBody;

/**
 * An app's own frontend component, named, with the props it is given.
 *
 *     FeedEntity::make()
 *         ->label("Match #{$this->id}")
 *         ->body(Component::make()->name('Common/ScoreCard')->props([
 *             'home' => $this->home_score,
 *             'away' => $this->away_score,
 *         ]));
 *
 * A frontend maps `name` to its component the way Inertia maps a page name
 * to a page, and hands it `props`. Storyfeed stores both and returns them
 * unchanged; what a renderer does with a name it does not know is the
 * renderer's business, and by rule 3 of {@see \Storyfeed\Contracts\FeedBody} it draws nothing.
 *
 * ## It replaced `component`
 *
 * `FeedEntity` used to carry a `component` string: a hint for the frontend
 * on how to draw the entity, with `data` as its props. It predated bodies,
 * and it was a custom body type by another name. It was retired on
 * 2026-09-23, and this is where it went.
 *
 * ## `$v` versions THIS type, not the app's props
 *
 * The props are the app's and change on the app's timeline, which this class
 * cannot see. When a component's props will change shape over time, a full
 * custom body type, with its own `upgrade()`, is the better fit.
 *
 * The version travels in both storage and payload: core does not own the app's
 * key, so the renderer must upgrade the body at read time, never write it back.
 */
class Component extends FeedBody
{
    protected ?string $name = null;

    /** @var array<string, mixed> */
    protected array $props = [];

    /**
     * Start a component. Both arguments are optional and have a method of the
     * same name; `name` must be set before the body is used.
     *
     * @param  string|null  $name  the frontend component's name, kept verbatim — `Common/ScoreCard`
     * @param  array<string, mixed>  $props  what the component is given
     */
    protected function __construct(?string $name = null, array $props = [])
    {
        $this->props($props);

        if ($name !== null) {
            $this->name($name);
        }
    }

    /**
     * The frontend component's name, kept verbatim: free-form, never
     * validated, and matched by the renderer exactly.
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Add props. An array MERGES — a repeated key takes the later value — and
     * a key with a value sets that one prop, as `View::with()` does:
     *
     *     ->props(['home' => 2, 'away' => 1])
     *     ->props('final', true)
     *
     * @param  array<string, mixed>|string  $key
     */
    public function props(array|string $key, mixed $value = null): static
    {
        if (is_string($key)) {
            $this->props[$key] = $value;

            return $this;
        }

        $this->props = array_merge($this->props, $key);

        return $this;
    }

    /**
     * `Storyfeed/Body/Component` — the VOCABULARY'S name, not a package's.
     *
     * The component's own name is a VALUE inside it, and belongs to the app.
     */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Component';
    }

    /** 2 since 2026-10-09: empty `props` are left out. */
    public static function version(): int
    {
        return 2;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a payload from a version this class does not know
        // still has to render, because the row is in the database either way.
        return [
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : '',
            'props' => is_array($payload['props'] ?? null) ? $payload['props'] : [],
        ];
    }

    protected function body(): array
    {
        return [
            'name' => $this->required($this->name, 'name'),
            'props' => $this->props,
        ];
    }

    protected static function defaults(): array
    {
        return ['props' => []];
    }
}
