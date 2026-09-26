<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;
use JsonSerializable;
use Storyfeed\FeedHeadline;
use Stringable;

/**
 * A feed item's sentence, read from `headline_template` (tokens still in
 * it) or `headline` (finished text).
 *
 *     {{ $item->headline() }}                      entity labels as links
 *     {{ $item->headline()->toString() }}          plain text
 *     $item->headline()->segments()                text and entity parts
 *
 * Every item reads as something. An item with neither field gets the words
 * a renderer is told to use (docs/payload.md): a group reads as its count,
 * "5 activities", never as prose borrowed from one member; a digest row
 * names its person once and joins its phrases; an activity reads
 * ":actor :verb :object". `isFallback()` says when that happened.
 *
 * The words are the package's lang lines (`storyfeed::feed.*`), so they
 * translate like any other line and an app can publish and change them.
 */
final class Headline implements Htmlable, JsonSerializable, Stringable
{
    use Conditionable, Dumpable, Macroable, Tappable;

    public function __construct(
        protected FeedItem $item,
        protected ?string $template,
        protected ?string $text,
    ) {}

    /** The template with its tokens, such as `:actor placed :object`, or null. */
    public function template(): ?string
    {
        return $this->template;
    }

    /** Whether the item carries no sentence, so the reader's fallback words are used. */
    public function isFallback(): bool
    {
        return $this->template === null && $this->text === null;
    }

    /**
     * The sentence in parts, in order. Each part is an array with a `type`
     * and the plain `text` it reads as:
     *
     * - `text`: connecting words, or a count.
     * - `entity`: one role, with its `role` and `entity` (an Entity, or null
     *   when the role is empty and `text` is the placeholder).
     * - `entities`: a plural role, with its `role`, `entities` (a
     *   Collection of Entity) and `total`, the true number of them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function segments(): Collection
    {
        if ($this->template !== null) {
            return $this->tokenize($this->template);
        }

        if ($this->text !== null) {
            return collect([self::text($this->text)]);
        }

        return $this->fallback();
    }

    /** The sentence as plain text. */
    public function toString(): string
    {
        return $this->segments()->map(fn (array $segment): string => $segment['text'])->implode('');
    }

    /**
     * The sentence as HTML, text escaped and each entity drawn by its own
     * `toHtml()`: a link when it has a url. Pass a closure to draw entities
     * your way; it receives the Entity and returns HTML.
     *
     * @param  (Closure(Entity): string)|null  $entity
     */
    public function toHtml(?Closure $entity = null): string
    {
        $draw = $entity ?? fn (Entity $entity): string => $entity->toHtml();

        return $this->segments()->map(fn (array $segment): string => match ($segment['type']) {
            'entity' => $segment['entity'] instanceof Entity ? $draw($segment['entity']) : e($segment['text']),
            'entities' => $this->listHtml($segment, $draw),
            default => e($segment['text']),
        })->implode('');
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function tokenize(string $template): Collection
    {
        $parts = preg_split('/(:[a-z]+)/', $template, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($parts)->map($this->segment(...))->values();
    }

    /** @return array<string, mixed> */
    protected function segment(string $part): array
    {
        if (! str_starts_with($part, ':')) {
            return self::text($part);
        }

        $token = substr($part, 1);
        $group = $this->item->offsetExists('sample');

        if ($token === 'count' && $this->item->offsetExists('count')) {
            return self::text((string) $this->item->count());
        }

        if ($token === 'others' && $group) {
            $others = max(0, $this->item->distinct('actor') - $this->item->actors()->count());

            return self::text(trans_choice('storyfeed::feed.others', $others, ['count' => $others]));
        }

        if (in_array($token, ActivityRoles::PAYLOAD, true)) {
            $entity = $this->item->entity($token);

            // A group names one entity for a singular token only when it
            // holds one: an unconditional first sample names one person
            // over a group of nine.
            if ($entity === null && $group) {
                if ($this->item->distinct($token) > 1) {
                    return $this->list($token);
                }

                $entity = $this->item->entities($token)->first();
            }

            return [
                'type' => 'entity',
                'text' => $entity?->toString() ?? Entity::placeholder($token),
                'role' => $token,
                'entity' => $entity,
            ];
        }

        if (str_ends_with($token, 's') && in_array(substr($token, 0, -1), ActivityRoles::PAYLOAD, true)) {
            return $this->list(substr($token, 0, -1));
        }

        return self::text($part);
    }

    /**
     * A plural role: the sample's labels, and how many more there are.
     *
     * @return array<string, mixed>
     */
    protected function list(string $role): array
    {
        $entities = $this->item->entities($role);
        $total = max($this->item->distinct($role), $entities->count());

        $words = $entities->map(fn (Entity $entity): string => $entity->toString());

        if ($total > $entities->count()) {
            $words->push(self::more($total - $entities->count()));
        }

        return [
            'type' => 'entities',
            'text' => $words->isEmpty() ? Entity::placeholder($role) : self::join($words),
            'role' => $role,
            'entities' => $entities,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  Closure(Entity): string  $draw
     */
    protected function listHtml(array $segment, Closure $draw): string
    {
        /** @var Collection<int, Entity> $entities */
        $entities = $segment['entities'];

        if ($entities->isEmpty() && $segment['total'] === 0) {
            return e($segment['text']);
        }

        $words = $entities->map($draw);

        if ($segment['total'] > $entities->count()) {
            $words->push(e(self::more($segment['total'] - $entities->count())));
        }

        return self::join($words, e(self::and()));
    }

    /**
     * The words for an item with no sentence.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function fallback(): Collection
    {
        if ($this->item->isDigest() && $this->item->phrases()->isNotEmpty()) {
            return $this->digest();
        }

        if ($this->item->isGroup()) {
            return collect([self::text(trans_choice('storyfeed::feed.activities', $this->item->count(), ['count' => $this->item->count()]))]);
        }

        $verb = (string) $this->item->verb();

        // A phrase: named by its verb and count.
        if ($this->item->kind() === null) {
            return $this->tokenize(str_replace(':verb', $verb, (string) __('storyfeed::feed.phrase')));
        }

        $template = FeedHeadline::resolveSegments(
            str_replace(':verb', $verb, (string) __('storyfeed::feed.unnamed')),
            fn (string $role): bool => $this->item->entity($role) !== null,
        );

        return $this->tokenize(trim((string) preg_replace('/ {2,}/', ' ', $template)));
    }

    /**
     * A digest row: the person once, then the phrases joined, then how many
     * activities the phrases do not cover.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function digest(): Collection
    {
        $person = $this->item->actor() !== null
            ? $this->tokenize(':actor')
            : collect([$this->list('actor')]);

        $phrases = $this->item->phrases()->map(fn (FeedItem $phrase): Collection => $phrase->headline()->segments());

        $more = $this->item->count() - $this->item->phrases()->sum(fn (FeedItem $phrase): int => $phrase->count());

        if ($more > 0) {
            $phrases->push(collect([self::text(self::more($more))]));
        }

        $segments = $person->push(self::text(' '));
        $last = $phrases->count() - 1;

        foreach ($phrases->values() as $index => $phrase) {
            if ($index > 0) {
                $segments->push(self::text($index === $last ? ' '.self::and().' ' : ', '));
            }

            $segments = $segments->concat($phrase);
        }

        return $segments->values();
    }

    /** @return array<string, mixed> */
    protected static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** "3 more" */
    protected static function more(int $count): string
    {
        return trans_choice('storyfeed::feed.more', $count, ['count' => $count]);
    }

    protected static function and(): string
    {
        return (string) __('storyfeed::feed.and');
    }

    /**
     * "A, B and C", the way `Collection::join()` puts it.
     *
     * @param  Collection<int, string>  $words
     */
    protected static function join(Collection $words, ?string $and = null): string
    {
        return $words->join(', ', ' '.($and ?? self::and()).' ');
    }
}
