<?php

namespace Storyfeed;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StoryfeedManager
{
    protected ?Closure $actorResolver = null;

    /** @var array<string, string|Closure> */
    protected array $grammar = [];

    /** @var array<string, string> */
    protected array $icons = [];

    /** @var array<string, string> */
    protected array $verbs = self::DEFAULT_VERBS;

    /** @var array<string, string> */
    protected array $objectTypes = [];

    /**
     * Built-in verb → Activity Streams 2.0 activity type mappings.
     * Unmapped verbs serialize as the base type `Activity` (spec-legal).
     */
    public const DEFAULT_VERBS = [
        'accept' => 'Accept',
        'add' => 'Add',
        'announce' => 'Announce',
        'arrive' => 'Arrive',
        'block' => 'Block',
        'create' => 'Create',
        'delete' => 'Delete',
        'dislike' => 'Dislike',
        'flag' => 'Flag',
        'follow' => 'Follow',
        'ignore' => 'Ignore',
        'invite' => 'Invite',
        'join' => 'Join',
        'leave' => 'Leave',
        'like' => 'Like',
        'listen' => 'Listen',
        'move' => 'Move',
        'offer' => 'Offer',
        'question' => 'Question',
        'read' => 'Read',
        'reject' => 'Reject',
        'remove' => 'Remove',
        'share' => 'Announce',
        'tentativeAccept' => 'TentativeAccept',
        'tentativeReject' => 'TentativeReject',
        'travel' => 'Travel',
        'undo' => 'Undo',
        'update' => 'Update',
        'view' => 'View',
    ];

    /**
     * Begin composing an activity.
     */
    public function activity(...$args): PendingActivity
    {
        return PendingActivity::make(...$args);
    }

    /**
     * Begin composing a feed query.
     */
    public function feed(): FeedBuilder
    {
        return new FeedBuilder;
    }

    /**
     * Register headline grammar. Keys are "type.verb" (wildcards allowed:
     * "delivery.*", "*.confirm", "*.*"); values are template strings with
     * :actor/:object/:target/:context placeholders, or closures receiving
     * the Activity and returning a pre-rendered headline.
     *
     * @param  array<string, string|Closure>  $grammar
     */
    public function grammar(array $grammar, bool $merge = true): static
    {
        $this->grammar = $merge ? [...$this->grammar, ...$grammar] : $grammar;

        return $this;
    }

    /**
     * Register icons, keyed like grammar ("type.verb", wildcards allowed).
     *
     * @param  array<string, string>  $icons
     */
    public function icons(array $icons, bool $merge = true): static
    {
        $this->icons = $merge ? [...$this->icons, ...$icons] : $icons;

        return $this;
    }

    /**
     * Register verb → AS2.0 activity type mappings (merged over defaults).
     *
     * @param  array<string, string>  $verbs
     */
    public function verbs(array $verbs, bool $merge = true): static
    {
        $this->verbs = $merge ? [...$this->verbs, ...$verbs] : $verbs;

        return $this;
    }

    /**
     * Register morph alias → AS2.0 object type mappings.
     *
     * @param  array<string, string>  $objectTypes
     */
    public function objectTypes(array $objectTypes, bool $merge = true): static
    {
        $this->objectTypes = $merge ? [...$this->objectTypes, ...$objectTypes] : $objectTypes;

        return $this;
    }

    /**
     * Resolve the grammar entry for an object type + verb.
     * Resolution order: type.verb → type.* → *.verb → *.*
     */
    public function template(?string $type, string $verb): string|Closure|null
    {
        return $this->resolve($this->grammar, $type, $verb);
    }

    /**
     * Resolve the icon for an object type + verb (same order as grammar).
     */
    public function icon(?string $type, string $verb): ?string
    {
        return $this->resolve($this->icons, $type, $verb);
    }

    /**
     * The AS2.0 activity type for a verb; null when unmapped.
     */
    public function activityType(string $verb): ?string
    {
        return $this->verbs[$verb] ?? null;
    }

    /**
     * The AS2.0 object type for a morph alias; null when unmapped.
     */
    public function objectType(string $alias): ?string
    {
        return $this->objectTypes[$alias] ?? null;
    }

    /** @return array<string, string|Closure> */
    public function registeredGrammar(): array
    {
        return $this->grammar;
    }

    /** @return array<string, string> */
    public function registeredIcons(): array
    {
        return $this->icons;
    }

    /**
     * Override how the default actor is resolved at publish time.
     */
    public function resolveActorUsing(Closure $resolver): void
    {
        $this->actorResolver = $resolver;
    }

    /**
     * Resolve the default actor for activities published without one.
     * Returns null for anonymous/system activities.
     */
    public function resolveActor(): ?Model
    {
        if ($this->actorResolver) {
            return ($this->actorResolver)();
        }

        if ($resolver = config('storyfeed.actor_resolver')) {
            return app($resolver)();
        }

        return Auth::user();
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $registry
     * @return TValue|null
     */
    protected function resolve(array $registry, ?string $type, string $verb)
    {
        $keys = $type === null
            ? ["*.{$verb}", '*.*']
            : ["{$type}.{$verb}", "{$type}.*", "*.{$verb}", '*.*'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $registry)) {
                return $registry[$key];
            }
        }

        return null;
    }
}
