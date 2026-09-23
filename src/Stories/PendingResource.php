<?php

namespace Storyfeed\Stories;

use InvalidArgumentException;
use Storyfeed\FeedNoun;

/**
 * The four lifecycle verbs of one model, defined in a line, as
 * `Route::resource()` defines a controller's actions:
 *
 *     Story::resource(Order::class);                            // create, update, delete, restore
 *     Story::resource(Order::class)->only(['create', 'update']);
 *     Story::resource(Document::class)->except('restore')->noun('document|documents');
 *
 * Each verb gets a headline (`:actor created :object`), an anonymous headline
 * (`:object was created`) and an icon. A group of them reads through the noun
 * registry: with `order|orders` registered, five creations by one person read
 * "Sally created orders" with no group headline written.
 *
 * REGISTERED WHEN CALLED, like the rest of the Story facade, and expanded into
 * definitions when stories compile, so `only()` and `except()` still apply
 * after the call. To say something else for one verb, leave it out here and
 * define it with `Story::for(Order::class)->verb('update')`; defining it in
 * both places is a conflict naming both lines.
 */
final class PendingResource
{
    /** The verbs, and what each says by default. */
    public const VERBS = [
        'create' => [':actor created :object', ':object was created', 'plus'],
        'update' => [':actor updated :object', ':object was updated', 'pencil'],
        'delete' => [':actor deleted :object', ':object was deleted', 'trash'],
        'restore' => [':actor restored :object', ':object was restored', 'rotate-ccw'],
    ];

    /** The verbs whose tombstoned object is expected ("deleted an order"). */
    public const REMOVALS = ['delete', 'restore'];

    /** @var list<string> */
    private array $verbs;

    private ?FeedNoun $noun = null;

    /**
     * @param  string|array<int, string>  $objectType  a model class, a morph alias, or a list
     */
    public function __construct(
        public readonly string|array $objectType,
        public readonly string $source,
    ) {
        $this->verbs = array_keys(self::VERBS);
    }

    /**
     * Define only these verbs.
     *
     * @param  string|array<int, string>  ...$verbs
     */
    public function only(string|array ...$verbs): self
    {
        $only = $this->validate($verbs);

        $this->verbs = array_values(array_filter($this->verbs, fn (string $verb) => in_array($verb, $only, true)));

        return $this;
    }

    /**
     * Define every verb but these.
     *
     * @param  string|array<int, string>  ...$verbs
     */
    public function except(string|array ...$verbs): self
    {
        $except = $this->validate($verbs);

        $this->verbs = array_values(array_filter($this->verbs, fn (string $verb) => ! in_array($verb, $except, true)));

        return $this;
    }

    /**
     * The type's noun, `'order|orders'`, which is what lets a group of these
     * say "5 orders". The same as `Story::for(Order::class)->noun(…)`.
     */
    public function noun(string|FeedNoun $noun): self
    {
        $this->noun = is_string($noun) ? FeedNoun::of($noun) : $noun;

        return $this;
    }

    /**
     * The definitions this resource stands for.
     *
     * @return list<Verb>
     *
     * @internal
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->verbs as $verb) {
            [$headline, $anonymous, $icon] = self::VERBS[$verb];

            $definition = Verb::for($this->objectType, $verb, $this->source)
                ->headline($headline)
                ->anonymousHeadline($anonymous)
                ->icon($icon);

            // Delete and restore are removal verbs: the object they name is
            // expected to be a tombstone, so it never makes them redundant.
            // Said explicitly rather than left to their AS2 types, which an
            // app's own verbs() may map differently.
            if (in_array($verb, self::REMOVALS, true)) {
                $definition->missing();
            }

            $definitions[] = $definition;
        }

        if ($this->noun !== null) {
            $definitions[] = Verb::for($this->objectType, '*', $this->source)->noun($this->noun);
        }

        return $definitions;
    }

    /**
     * @param  array<int, string|array<int, string>>  $verbs
     * @return list<string>
     */
    private function validate(array $verbs): array
    {
        $verbs = array_merge(...array_map(fn (string|array $verb) => array_values((array) $verb), $verbs));

        foreach ($verbs as $verb) {
            if (! array_key_exists($verb, self::VERBS)) {
                throw new InvalidArgumentException(
                    "Story::resource() has no [{$verb}] verb. It defines ".implode(', ', array_keys(self::VERBS)).'.',
                );
            }
        }

        return $verbs;
    }
}
